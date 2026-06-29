<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

/**
 * Sprint 21 — deterministic Root Cause Analysis.
 *
 * Builds an evidence-based causal chain across the resource layers
 * (CPU → Memory → Storage → Network → Service/Application) by scoring each layer's "pressure" from
 * REAL signals only: latest metric value vs. thresholds, the metric's recent slope, related service
 * states, and open alerts. The most probable root cause is the most-pressured FOUNDATIONAL layer
 * (resource layers rank ahead of application symptoms, matching the classic
 * CPU↓Memory↓Storage↓Database↓Application escalation). The LLM never decides the cause — it only
 * narrates this deterministic result. Confidence reflects evidence coverage, never a guess.
 */
class RootCauseService
{
    /** [warning, critical] utilization thresholds per metric (mirrors QynSight performance thresholds). */
    private const THRESHOLDS = [
        'cpu' => [70.0, 90.0],
        'memory' => [80.0, 95.0],
        'disk' => [85.0, 95.0],
        'network' => [70.0, 90.0],
    ];

    /** Foundational ordering: lower index = more foundational (a more likely true root cause). */
    private const LAYER_ORDER = ['cpu', 'memory', 'disk', 'network', 'service'];

    /**
     * @param  array<string, mixed>  $signals  ['latest_metrics'=>[], 'recent_metrics'=>[], 'services'=>[], 'alerts'=>[]]
     * @return array<string, mixed>
     */
    public function analyze(array $signals): array
    {
        $latest = (array) ($signals['latest_metrics'] ?? []);
        $recent = (array) ($signals['recent_metrics'] ?? []);
        $services = (array) ($signals['services'] ?? []);
        $alerts = (array) ($signals['alerts'] ?? []);

        $chain = [];
        $evidenceLayers = 0;

        foreach (['cpu', 'memory', 'disk', 'network'] as $metric) {
            $value = $this->numericOrNull($latest[$metric] ?? null);
            $trend = is_array($recent[$metric] ?? null) ? $recent[$metric] : null;
            $hasData = $value !== null || ($trend['available'] ?? false);

            if ($hasData) {
                $evidenceLayers++;
            }

            [$warn, $crit] = self::THRESHOLDS[$metric];
            $slope = (float) ($trend['slope_per_hour'] ?? 0.0);
            $observed = $value ?? ($trend['last'] ?? null);

            $pressure = $this->metricPressure($observed, $slope, $warn, $crit);
            $alertBoost = $this->alertPressureFor($alerts, $metric);

            $chain[] = [
                'layer' => $metric,
                'observed_value' => $observed !== null ? round((float) $observed, 2) : null,
                'slope_per_hour' => $slope,
                'thresholds' => ['warning' => $warn, 'critical' => $crit],
                'related_alerts' => $alertBoost['count'],
                'pressure' => round(min(100.0, $pressure + $alertBoost['boost']), 2),
                'has_evidence' => $hasData,
                'state' => $this->classify($observed, $warn, $crit),
            ];
        }

        // Service / application layer: derived from non-ok service states + service-level alerts.
        $badServices = array_values(array_filter($services, fn ($s): bool => in_array(($s['state'] ?? 'ok'), ['critical', 'warning', 'unreachable'], true)));
        $serviceAlerts = array_values(array_filter($alerts, fn ($a): bool => ($a['service'] ?? null) !== null));
        if ($services !== [] || $serviceAlerts !== []) {
            $evidenceLayers++;
        }
        $servicePressure = min(100.0, (count($badServices) * 25.0) + (count($serviceAlerts) * 15.0));
        $chain[] = [
            'layer' => 'service',
            'observed_value' => null,
            'degraded_services' => array_map(fn ($s): array => ['name' => $s['name'] ?? null, 'state' => $s['state'] ?? null], array_slice($badServices, 0, 10)),
            'related_alerts' => count($serviceAlerts),
            'pressure' => round($servicePressure, 2),
            'has_evidence' => $services !== [] || $serviceAlerts !== [],
            'state' => $servicePressure >= 50 ? 'critical' : ($servicePressure > 0 ? 'warning' : 'ok'),
        ];

        // Rank: highest pressure first; ties broken by how foundational the layer is.
        usort($chain, function (array $a, array $b): int {
            if ($a['pressure'] === $b['pressure']) {
                return array_search($a['layer'], self::LAYER_ORDER, true) <=> array_search($b['layer'], self::LAYER_ORDER, true);
            }

            return $b['pressure'] <=> $a['pressure'];
        });

        $top = $chain[0] ?? null;
        $rootCause = ($top !== null && $top['pressure'] > 0)
            ? [
                'layer' => $top['layer'],
                'state' => $top['state'],
                'observed_value' => $top['observed_value'],
                'summary' => $this->rootCauseSummary($top),
            ]
            : null;

        return [
            'method' => 'deterministic',
            'chain' => $chain,
            'root_cause' => $rootCause,
            'confidence' => $this->confidence($evidenceLayers, $rootCause !== null, $top['pressure'] ?? 0.0),
            'evidence_layers' => $evidenceLayers,
        ];
    }

    private function metricPressure(mixed $observed, float $slope, float $warn, float $crit): float
    {
        $observed = $this->numericOrNull($observed);
        if ($observed === null) {
            return 0.0;
        }

        $pressure = 0.0;
        if ($observed >= $crit) {
            $pressure = 80.0 + min(20.0, ($observed - $crit));
        } elseif ($observed >= $warn) {
            $range = max(1.0, $crit - $warn);
            $pressure = 40.0 + (($observed - $warn) / $range) * 40.0;
        } else {
            $pressure = max(0.0, ($observed / max(1.0, $warn)) * 30.0);
        }

        // Rising trend toward a ceiling adds urgency.
        if ($slope > 0) {
            $pressure += min(15.0, $slope);
        }

        return min(100.0, $pressure);
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return array{count: int, boost: float}
     */
    private function alertPressureFor(array $alerts, string $metric): array
    {
        $count = 0;
        foreach ($alerts as $alert) {
            $haystack = strtolower((string) (($alert['service'] ?? '').' '.($alert['title'] ?? '').' '.($alert['message'] ?? '')));
            if (str_contains($haystack, $metric)) {
                $count++;
            }
        }

        return ['count' => $count, 'boost' => min(20.0, $count * 10.0)];
    }

    private function classify(mixed $observed, float $warn, float $crit): string
    {
        $observed = $this->numericOrNull($observed);
        if ($observed === null) {
            return 'unknown';
        }
        if ($observed >= $crit) {
            return 'critical';
        }
        if ($observed >= $warn) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param  array<string, mixed>  $top
     */
    private function rootCauseSummary(array $top): string
    {
        $layer = (string) $top['layer'];
        $state = (string) $top['state'];
        $value = $top['observed_value'];

        if ($layer === 'service') {
            return 'Service/application layer is degraded based on current check states and related alerts.';
        }

        $label = ['cpu' => 'CPU', 'memory' => 'memory', 'disk' => 'storage', 'network' => 'network'][$layer] ?? $layer;

        return $value !== null
            ? sprintf('%s pressure (%.1f%%, state: %s) is the most-pressured foundational layer.', ucfirst($label), (float) $value, $state)
            : sprintf('%s is the most-pressured layer based on alerts and trend.', ucfirst($label));
    }

    private function confidence(int $evidenceLayers, bool $hasRoot, float $topPressure): float
    {
        if (! $hasRoot || $evidenceLayers === 0) {
            return 0.0;
        }

        $coverage = min(1.0, $evidenceLayers / count(self::LAYER_ORDER));
        $strength = min(1.0, $topPressure / 100.0);

        return round(0.4 * $coverage + 0.6 * $strength, 2);
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}

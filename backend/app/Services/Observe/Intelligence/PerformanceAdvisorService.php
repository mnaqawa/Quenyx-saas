<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Support\Observe\OperationsEntityId;

/**
 * Sprint 21 — Performance Intelligence.
 *
 * Deterministically surfaces resource hotspots, slow checks, trend changes, and anomalies from REAL
 * metric history and check latencies. Hotspots are utilization over threshold; trend changes come
 * from the metric slope; anomalies are a current value far above its window average; slow services
 * use the real per-check latency/execution time. The AI layer narrates these findings — it does not
 * detect them.
 */
class PerformanceAdvisorService
{
    private const THRESHOLDS = [
        'cpu' => [70.0, 90.0],
        'memory' => [80.0, 95.0],
        'disk' => [85.0, 95.0],
        'network' => [70.0, 90.0],
    ];

    /** A check is "slow" above this latency (seconds). */
    private const SLOW_CHECK_SECONDS = 5.0;

    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(Project $project, ?ObserveTargetHost $host = null): array
    {
        $hosts = $host !== null
            ? collect([$host])
            : ObserveTargetHost::query()->where('workspace_id', $project->id)->where('enabled', true)->get();

        $hotspots = [];
        $trendChanges = [];
        $anomalies = [];

        foreach ($hosts as $h) {
            $prefixed = $this->evidence->hostPrefix($project).$h->name;
            $metrics = $this->evidence->recentMetrics($project, $prefixed, ['cpu', 'memory', 'disk', 'network'], 24);

            foreach ($metrics as $metric => $data) {
                if (! ($data['available'] ?? false)) {
                    continue;
                }

                [$warn, $crit] = self::THRESHOLDS[$metric] ?? [70.0, 90.0];
                $last = (float) $data['last'];
                $avg = (float) $data['avg'];
                $slope = (float) $data['slope_per_hour'];

                if ($last >= $warn) {
                    $hotspots[] = [
                        'host' => (string) $h->name,
                        'metric' => $metric,
                        'value' => round($last, 2),
                        'severity' => $last >= $crit ? 'critical' : 'warning',
                    ];
                }

                if (abs($slope) >= 1.0) {
                    $trendChanges[] = [
                        'host' => (string) $h->name,
                        'metric' => $metric,
                        'slope_per_hour' => $slope,
                        'direction' => $slope > 0 ? 'increasing' : 'decreasing',
                    ];
                }

                // Anomaly: current sample materially above its own window average.
                if ($avg > 0 && $last >= ($avg * 1.5) && $last >= $warn) {
                    $anomalies[] = [
                        'host' => (string) $h->name,
                        'metric' => $metric,
                        'value' => round($last, 2),
                        'window_avg' => round($avg, 2),
                    ];
                }
            }
        }

        // Slow checks across the workspace (or the single host).
        $slowQuery = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('check_latency_sec', '>=', self::SLOW_CHECK_SECONDS);
        if ($host !== null) {
            $slowQuery->where('host_name', $this->evidence->hostPrefix($project).$host->name);
        } else {
            $slowQuery->where('host_name', 'like', $this->evidence->hostPrefix($project).'%');
        }
        $slowServices = $slowQuery->orderByDesc('check_latency_sec')
            ->limit(20)
            ->get(['id', 'service_name', 'host_name', 'check_latency_sec', 'execution_time_sec'])
            ->map(fn (ObserveService $s): array => [
                'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_SERVICE, $project->id, (int) $s->id),
                'name' => (string) $s->service_name,
                'host' => $this->evidence->unprefixHost($project, (string) $s->host_name),
                'check_latency_sec' => (float) $s->check_latency_sec,
                'execution_time_sec' => (float) $s->execution_time_sec,
            ])
            ->all();

        usort($hotspots, fn ($a, $b): int => $b['value'] <=> $a['value']);

        return [
            'scope' => $host !== null ? 'host' : 'workspace',
            'host' => $host !== null ? $this->evidence->hostSnapshot($project, $host) : null,
            'resource_hotspots' => array_slice($hotspots, 0, 25),
            'trend_changes' => array_slice($trendChanges, 0, 25),
            'anomalies' => array_slice($anomalies, 0, 25),
            'slow_services' => $slowServices,
            'has_findings' => $hotspots !== [] || $trendChanges !== [] || $anomalies !== [] || $slowServices !== [],
        ];
    }
}

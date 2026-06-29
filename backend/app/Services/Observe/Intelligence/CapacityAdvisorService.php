<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveTargetHost;
use App\Models\Project;

/**
 * Sprint 21 — Capacity Intelligence.
 *
 * Reuses the existing {@see \App\Services\CapacityPlanningService} for workspace-level capacity
 * analytics (growth trend, forecast, runway/exhaustion, risk) and adds a deterministic PER-HOST
 * forecast derived only from that host's real cpu/memory/disk history. No predictions are fabricated:
 * when there is not enough history, the forecast honestly reports "insufficient_data".
 */
class CapacityAdvisorService
{
    /** Resource is considered "exhausted" at this utilization ceiling. */
    private const CEILING = 100.0;

    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function predict(Project $project, ?ObserveTargetHost $host = null, string $range = '30d'): array
    {
        $workspaceCapacity = $this->evidence->capacity($project, $range);

        if ($host === null) {
            return [
                'scope' => 'workspace',
                'workspace_capacity' => $workspaceCapacity,
                'host_forecast' => null,
            ];
        }

        $prefixed = $this->evidence->hostPrefix($project).$host->name;
        // 30-day window to derive a stable per-host slope from real samples.
        $metrics = $this->evidence->recentMetrics($project, $prefixed, ['cpu', 'memory', 'disk'], 24 * 30);

        $forecast = [];
        foreach (['cpu', 'memory', 'disk'] as $metric) {
            $forecast[$metric] = $this->forecastMetric($metrics[$metric] ?? ['available' => false]);
        }

        return [
            'scope' => 'host',
            'host' => $this->evidence->hostSnapshot($project, $host),
            'host_forecast' => $forecast,
            'workspace_capacity' => $workspaceCapacity,
        ];
    }

    /**
     * @param  array<string, mixed>  $metric
     * @return array<string, mixed>
     */
    private function forecastMetric(array $metric): array
    {
        if (! ($metric['available'] ?? false)) {
            return ['available' => false, 'reason' => 'insufficient_data', 'samples' => (int) ($metric['samples'] ?? 0)];
        }

        $current = (float) ($metric['last'] ?? 0.0);
        $slopePerHour = (float) ($metric['slope_per_hour'] ?? 0.0);
        $slopePerDay = $slopePerHour * 24.0;

        $projected30d = round(min(self::CEILING, max(0.0, $current + ($slopePerDay * 30.0))), 2);

        $runwayDays = null;
        $exhaustionAt = null;
        if ($slopePerDay > 0.0001 && $current < self::CEILING) {
            $runwayDays = (int) floor((self::CEILING - $current) / $slopePerDay);
            $exhaustionAt = now()->addDays(max(0, $runwayDays))->toIso8601String();
        }

        return [
            'available' => true,
            'current' => round($current, 2),
            'trend' => $slopePerDay > 0.01 ? 'increasing' : ($slopePerDay < -0.01 ? 'decreasing' : 'stable'),
            'slope_per_day' => round($slopePerDay, 3),
            'projected_30d' => $projected30d,
            'runway_days' => $runwayDays,
            'estimated_exhaustion_at' => $exhaustionAt,
            'status' => $this->status($current, $runwayDays),
        ];
    }

    private function status(float $current, ?int $runwayDays): string
    {
        if ($runwayDays !== null && $runwayDays <= 30) {
            return 'critical';
        }
        if ($current >= 90.0 || ($runwayDays !== null && $runwayDays <= 90)) {
            return 'warning';
        }

        return 'healthy';
    }
}

<?php

namespace App\Services;

use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Derives capacity-planning payloads from native observe metrics history
 * and current service states. No fabricated business metrics.
 */
class CapacityPlanningService
{
    private const THRESHOLD_CRITICAL_MONTHS = 3.0;

    private const THRESHOLD_WARNING_MONTHS = 6.0;

    private const MIN_POINTS_FOR_RUNWAY = 5;

    /**
     * @return array<string, mixed>
     */
    public function build(int $workspaceId, string $range = '30d'): array
    {
        $empty = $this->emptyPayload($range);

        if (! Schema::hasTable('observe_metrics_history')) {
            return $empty;
        }

        [$from, $bucketSeconds] = $this->resolveRange($range);
        $to = now();
        $workspacePrefix = 'ws' . $workspaceId . '-';

        $rows = ObserveMetricHistory::query()
            ->where('workspace_id', $workspaceId)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<=', $to)
            ->whereIn('metric', ['cpu', 'memory', 'disk'])
            ->orderBy('recorded_at')
            ->get(['host_name', 'service_name', 'metric', 'value', 'recorded_at']);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $dailySeries = $this->buildDailySeries($rows, $bucketSeconds);
        $latestByHost = $this->latestByHostMetric($rows, $workspacePrefix);
        $hostConsumers = $this->buildConsumerLists($latestByHost);

        $cpuRunway = $this->runwayMonths($dailySeries['cpu'] ?? []);
        $memoryRunway = $this->runwayMonths($dailySeries['memory'] ?? []);
        $storageRunway = $this->runwayMonths($dailySeries['disk'] ?? []);

        $latestCpu = $this->latestAverage($dailySeries['cpu'] ?? []);
        $latestMemory = $this->latestAverage($dailySeries['memory'] ?? []);
        $latestDisk = $this->latestAverage($dailySeries['disk'] ?? []);

        $riskScore = $this->capacityRiskScore($latestCpu, $latestMemory, $latestDisk, $cpuRunway, $memoryRunway, $storageRunway);

        $forecast = $this->buildForecastSeries($dailySeries);
        $growthTrends = $this->buildGrowthTrends($dailySeries);
        $insights = $this->buildOptimizationInsights($latestByHost, $workspaceId);
        $scenarios = $this->buildScenarios($dailySeries, $cpuRunway, $memoryRunway, $storageRunway);
        $advisor = $this->buildAdvisorSummary(
            $cpuRunway,
            $memoryRunway,
            $storageRunway,
            $riskScore,
            count($insights),
            $latestByHost
        );

        $dataAvailable = ! empty($forecast) || ! empty($hostConsumers['top_cpu_consumers']);

        return [
            'summary' => [
                'cpu_runway_months' => $cpuRunway,
                'memory_runway_months' => $memoryRunway,
                'storage_runway_months' => $storageRunway,
                'cost_optimization_potential' => null,
                'capacity_risk_score' => $riskScore,
                'statuses' => [
                    'cpu' => $this->runwayStatus($cpuRunway),
                    'memory' => $this->runwayStatus($memoryRunway),
                    'storage' => $this->runwayStatus($storageRunway),
                    'cost' => 'insufficient_data',
                    'risk' => $riskScore === null ? 'insufficient_data' : $this->riskStatus($riskScore),
                ],
            ],
            'overview' => [
                'forecast' => $forecast,
                'growth_trends' => $growthTrends,
                'advisor' => $advisor,
            ],
            'resource_analysis' => [
                'top_cpu_consumers' => $hostConsumers['top_cpu_consumers'],
                'top_memory_consumers' => $hostConsumers['top_memory_consumers'],
                'top_storage_consumers' => $hostConsumers['top_storage_consumers'],
                'distribution' => $hostConsumers['distribution'],
            ],
            'optimization_insights' => $insights,
            'scenario_planning' => $scenarios,
            'budget_planning' => [
                'current_monthly_cost' => null,
                'forecasted_cost' => [],
                'budget_variance' => null,
                'saving_opportunities' => [],
                'provider_breakdown' => [],
            ],
            'meta' => [
                'data_available' => $dataAvailable,
                'last_updated' => $to->toIso8601String(),
                'range' => $range,
                'history_points' => $rows->count(),
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: int}
     */
    private function resolveRange(string $range): array
    {
        return match ($range) {
            '7d' => [now()->subDays(7), 3600],
            '90d' => [now()->subDays(90), 86400],
            default => [now()->subDays(30), 86400],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $range): array
    {
        return [
            'summary' => [
                'cpu_runway_months' => null,
                'memory_runway_months' => null,
                'storage_runway_months' => null,
                'cost_optimization_potential' => null,
                'capacity_risk_score' => null,
                'statuses' => [
                    'cpu' => 'insufficient_data',
                    'memory' => 'insufficient_data',
                    'storage' => 'insufficient_data',
                    'cost' => 'insufficient_data',
                    'risk' => 'insufficient_data',
                ],
            ],
            'overview' => [
                'forecast' => [],
                'growth_trends' => [],
                'advisor' => null,
            ],
            'resource_analysis' => [
                'top_cpu_consumers' => [],
                'top_memory_consumers' => [],
                'top_storage_consumers' => [],
                'distribution' => [],
            ],
            'optimization_insights' => [],
            'scenario_planning' => [],
            'budget_planning' => [
                'current_monthly_cost' => null,
                'forecasted_cost' => [],
                'budget_variance' => null,
                'saving_opportunities' => [],
                'provider_breakdown' => [],
            ],
            'meta' => [
                'data_available' => false,
                'last_updated' => null,
                'range' => $range,
                'history_points' => 0,
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ObserveMetricHistory>  $rows
     * @return array<string, list<array{timestamp: int, value: float}>>
     */
    private function buildDailySeries($rows, int $bucketSeconds): array
    {
        $bucketed = [];
        foreach ($rows as $row) {
            $recordedAt = $row->recorded_at;
            if ($recordedAt === null) {
                continue;
            }
            $metric = (string) $row->metric;
            $bucketTs = intdiv($recordedAt->getTimestamp(), $bucketSeconds) * $bucketSeconds;
            $bucketed[$metric][$bucketTs][] = (float) $row->value;
        }

        $series = [];
        foreach ($bucketed as $metric => $buckets) {
            ksort($buckets);
            $series[$metric] = [];
            foreach ($buckets as $ts => $values) {
                $series[$metric][] = [
                    'timestamp' => $ts,
                    'value' => round(array_sum($values) / count($values), 2),
                ];
            }
        }

        return $series;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ObserveMetricHistory>  $rows
     * @return array<string, array{host: string, cpu: ?float, memory: ?float, disk: ?float}>
     */
    private function latestByHostMetric($rows, string $workspacePrefix): array
    {
        $byHost = [];
        foreach ($rows as $row) {
            $hostName = (string) $row->host_name;
            $displayHost = str_starts_with($hostName, $workspacePrefix)
                ? substr($hostName, strlen($workspacePrefix))
                : $hostName;
            $metric = (string) $row->metric;
            $recordedAt = $row->recorded_at;
            if ($recordedAt === null) {
                continue;
            }
            $ts = $recordedAt->getTimestamp();
            if (! isset($byHost[$displayHost])) {
                $byHost[$displayHost] = ['host' => $displayHost, 'cpu' => null, 'memory' => null, 'disk' => null, '_ts' => []];
            }
            $prevTs = $byHost[$displayHost]['_ts'][$metric] ?? 0;
            if ($ts >= $prevTs) {
                $byHost[$displayHost]['_ts'][$metric] = $ts;
                $byHost[$displayHost][$metric] = round((float) $row->value, 2);
            }
        }

        foreach ($byHost as &$host) {
            unset($host['_ts']);
        }

        return $byHost;
    }

    /**
     * @param  array<string, array{host: string, cpu: ?float, memory: ?float, disk: ?float}>  $latestByHost
     * @return array{top_cpu_consumers: list<array>, top_memory_consumers: list<array>, top_storage_consumers: list<array>, distribution: list<array>}
     */
    private function buildConsumerLists(array $latestByHost): array
    {
        $toRanked = function (string $key) use ($latestByHost): array {
            $items = [];
            foreach ($latestByHost as $host) {
                $val = $host[$key] ?? null;
                if ($val === null) {
                    continue;
                }
                $items[] = [
                    'host' => $host['host'],
                    'value_pct' => $val,
                    'metric' => $key === 'disk' ? 'storage' : $key,
                ];
            }
            usort($items, fn ($a, $b) => $b['value_pct'] <=> $a['value_pct']);

            return array_slice($items, 0, 10);
        };

        $distribution = [];
        foreach ($latestByHost as $host) {
            $distribution[] = [
                'host' => $host['host'],
                'environment' => 'monitored',
                'cpu_pct' => $host['cpu'],
                'memory_pct' => $host['memory'],
                'storage_pct' => $host['disk'],
            ];
        }

        return [
            'top_cpu_consumers' => $toRanked('cpu'),
            'top_memory_consumers' => $toRanked('memory'),
            'top_storage_consumers' => $toRanked('disk'),
            'distribution' => $distribution,
        ];
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     */
    private function runwayMonths(array $series): ?float
    {
        if (count($series) < self::MIN_POINTS_FOR_RUNWAY) {
            return null;
        }

        $regression = $this->linearRegression($series);
        if ($regression === null) {
            return null;
        }

        [$slopePerDay, $current] = $regression;

        if ($current >= 99) {
            return 0.0;
        }

        if ($slopePerDay <= 0) {
            return null;
        }

        $daysToLimit = (100 - $current) / $slopePerDay;

        return round($daysToLimit / 30.4375, 1);
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     * @return array{0: float, 1: float}|null  [slope per day, latest value]
     */
    private function linearRegression(array $series): ?array
    {
        $n = count($series);
        if ($n < 2) {
            return null;
        }

        $firstTs = $series[0]['timestamp'];
        $xs = [];
        $ys = [];
        foreach ($series as $point) {
            $xs[] = ($point['timestamp'] - $firstTs) / 86400;
            $ys[] = $point['value'];
        }

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;
        $num = 0.0;
        $den = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($xs[$i] - $meanX) * ($ys[$i] - $meanY);
            $den += ($xs[$i] - $meanX) ** 2;
        }

        if ($den == 0.0) {
            return null;
        }

        $slope = $num / $den;
        $latest = $ys[$n - 1];

        return [$slope, $latest];
    }

    private function runwayStatus(?float $months): string
    {
        if ($months === null) {
            return 'insufficient_data';
        }
        if ($months <= self::THRESHOLD_CRITICAL_MONTHS) {
            return 'critical';
        }
        if ($months <= self::THRESHOLD_WARNING_MONTHS) {
            return 'warning';
        }

        return 'healthy';
    }

    private function riskStatus(float $score): string
    {
        if ($score >= 75) {
            return 'critical';
        }
        if ($score >= 50) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     */
    private function latestAverage(array $series): ?float
    {
        if (empty($series)) {
            return null;
        }

        return $series[count($series) - 1]['value'];
    }

    private function capacityRiskScore(
        ?float $cpu,
        ?float $memory,
        ?float $disk,
        ?float $cpuRunway,
        ?float $memoryRunway,
        ?float $storageRunway
    ): ?float {
        $utils = array_filter([$cpu, $memory, $disk], fn ($v) => $v !== null);
        if (empty($utils)) {
            return null;
        }

        $utilScore = max($utils);
        $runways = array_filter([$cpuRunway, $memoryRunway, $storageRunway], fn ($v) => $v !== null);
        $runwayScore = 0.0;
        if (! empty($runways)) {
            $minRunway = min($runways);
            if ($minRunway <= 1) {
                $runwayScore = 100;
            } elseif ($minRunway <= 3) {
                $runwayScore = 80;
            } elseif ($minRunway <= 6) {
                $runwayScore = 55;
            } elseif ($minRunway <= 12) {
                $runwayScore = 30;
            } else {
                $runwayScore = 10;
            }
        }

        return round(min(100, ($utilScore * 0.6) + ($runwayScore * 0.4)), 1);
    }

    /**
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @return list<array<string, mixed>>
     */
    private function buildForecastSeries(array $dailySeries): array
    {
        $points = [];
        $metrics = ['cpu', 'memory', 'disk'];
        $allTimestamps = [];

        foreach ($metrics as $metric) {
            foreach ($dailySeries[$metric] ?? [] as $point) {
                $allTimestamps[$point['timestamp']] = true;
            }
        }

        ksort($allTimestamps);
        foreach (array_keys($allTimestamps) as $ts) {
            $row = [
                'time' => Carbon::createFromTimestamp($ts)->toIso8601String(),
                'label' => Carbon::createFromTimestamp($ts)->format('M d'),
                'cpu' => $this->valueAtTimestamp($dailySeries['cpu'] ?? [], $ts),
                'memory' => $this->valueAtTimestamp($dailySeries['memory'] ?? [], $ts),
                'storage' => $this->valueAtTimestamp($dailySeries['disk'] ?? [], $ts),
                'projected' => false,
            ];
            $points[] = $row;
        }

        $projected = $this->projectForward($dailySeries, 30);
        foreach ($projected as $row) {
            $points[] = $row;
        }

        return $points;
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     */
    private function valueAtTimestamp(array $series, int $ts): ?float
    {
        foreach ($series as $point) {
            if ($point['timestamp'] === $ts) {
                return $point['value'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @return list<array<string, mixed>>
     */
    private function projectForward(array $dailySeries, int $days): array
    {
        $out = [];
        $map = ['cpu' => 'cpu', 'memory' => 'memory', 'disk' => 'storage'];
        $lastTs = 0;
        $regressions = [];

        foreach ($map as $src => $dest) {
            $series = $dailySeries[$src] ?? [];
            if (empty($series)) {
                continue;
            }
            $lastTs = max($lastTs, $series[count($series) - 1]['timestamp']);
            $reg = $this->linearRegression($series);
            if ($reg !== null) {
                $regressions[$dest] = ['reg' => $reg, 'series' => $series];
            }
        }

        if ($lastTs === 0 || empty($regressions)) {
            return [];
        }

        for ($d = 1; $d <= $days; $d++) {
            $ts = $lastTs + ($d * 86400);
            $row = [
                'time' => Carbon::createFromTimestamp($ts)->toIso8601String(),
                'label' => Carbon::createFromTimestamp($ts)->format('M d'),
                'cpu' => null,
                'memory' => null,
                'storage' => null,
                'projected' => true,
            ];
            foreach ($regressions as $dest => $data) {
                [$slope, $latest] = $data['reg'];
                $lastPointTs = $data['series'][count($data['series']) - 1]['timestamp'];
                $daysFromLast = ($ts - $lastPointTs) / 86400;
                $row[$dest] = min(100, max(0, round($latest + ($slope * $daysFromLast), 2)));
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @return list<array<string, mixed>>
     */
    private function buildGrowthTrends(array $dailySeries): array
    {
        $trends = [];
        foreach (['cpu' => 'cpu', 'memory' => 'memory', 'disk' => 'storage'] as $src => $label) {
            $series = $dailySeries[$src] ?? [];
            if (count($series) < 2) {
                continue;
            }
            $first = $series[0]['value'];
            $last = $series[count($series) - 1]['value'];
            $delta = round($last - $first, 2);
            $reg = $this->linearRegression($series);
            $slope = $reg ? round($reg[0] * 30.4375, 2) : null;
            $trends[] = [
                'metric' => $label,
                'start_pct' => $first,
                'end_pct' => $last,
                'change_pct' => $delta,
                'monthly_growth_pct' => $slope,
            ];
        }

        return $trends;
    }

    /**
     * @param  array<string, array{host: string, cpu: ?float, memory: ?float, disk: ?float}>  $latestByHost
     * @return list<array<string, mixed>>
     */
    private function buildOptimizationInsights(array $latestByHost, int $workspaceId): array
    {
        $insights = [];
        $now = now()->toIso8601String();

        $criticalServices = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('engine_key', 'native')
            ->whereIn('state', ['critical', 'warning'])
            ->where(function ($q) {
                $q->where('service_name', 'like', '%cpu%')
                    ->orWhere('service_name', 'like', '%memory%')
                    ->orWhere('service_name', 'like', '%disk%')
                    ->orWhere('service_name', 'like', '%load%')
                    ->orWhere('service_name', 'like', '%space%');
            })
            ->orderByDesc('last_check_at')
            ->limit(10)
            ->get(['host_name', 'service_name', 'state', 'output', 'last_check_at']);

        foreach ($latestByHost as $host) {
            $checks = [
                ['metric' => 'cpu', 'value' => $host['cpu'], 'warn' => 70, 'crit' => 85],
                ['metric' => 'memory', 'value' => $host['memory'], 'warn' => 75, 'crit' => 90],
                ['metric' => 'storage', 'value' => $host['disk'], 'warn' => 80, 'crit' => 92],
            ];
            foreach ($checks as $check) {
                $val = $check['value'];
                if ($val === null) {
                    continue;
                }
                if ($val < $check['warn']) {
                    continue;
                }
                $priority = $val >= $check['crit'] ? 'high' : 'medium';
                $insights[] = [
                    'id' => 'host-' . $host['host'] . '-' . $check['metric'],
                    'priority' => $priority,
                    'affected_resource' => $host['host'],
                    'issue' => strtoupper($check['metric']) . ' utilization at ' . $val . '%',
                    'recommendation' => $priority === 'high'
                        ? 'Scale or redistribute workload before capacity limits are reached.'
                        : 'Review workload and plan scaling within the current growth window.',
                    'expected_impact' => 'Reduces risk of service degradation on ' . $host['host'],
                    'estimated_saving' => null,
                    'created_at' => $now,
                ];
            }
        }

        foreach ($criticalServices as $svc) {
            $host = preg_replace('/^ws\d+-/', '', (string) $svc->host_name);
            $insights[] = [
                'id' => 'svc-' . $svc->host_name . '-' . $svc->service_name,
                'priority' => $svc->state === 'critical' ? 'high' : 'medium',
                'affected_resource' => $host . ' / ' . $svc->service_name,
                'issue' => ucfirst((string) $svc->state) . ' service check: ' . mb_substr((string) ($svc->output ?? ''), 0, 120),
                'recommendation' => 'Investigate the failing capacity-related check and remediate root cause.',
                'expected_impact' => 'Restores reliable monitoring signal for capacity decisions.',
                'estimated_saving' => null,
                'created_at' => $svc->last_check_at?->toIso8601String() ?? $now,
            ];
        }

        usort($insights, fn ($a, $b) => ($a['priority'] === 'high' ? 0 : 1) <=> ($b['priority'] === 'high' ? 0 : 1));

        return array_slice($insights, 0, 20);
    }

    /**
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @return list<array<string, mixed>>
     */
    private function buildScenarios(
        array $dailySeries,
        ?float $cpuRunway,
        ?float $memoryRunway,
        ?float $storageRunway
    ): array {
        $runways = array_filter([
            'cpu' => $cpuRunway,
            'memory' => $memoryRunway,
            'storage' => $storageRunway,
        ], fn ($v) => $v !== null);

        if (empty($runways)) {
            return [];
        }

        $minMetric = array_search(min($runways), $runways, true);
        $baseRunway = $runways[$minMetric];

        return [
            [
                'id' => 'current_growth',
                'name' => 'current_growth',
                'description' => 'Projection based on observed utilization trend.',
                'limiting_resource' => $minMetric === 'disk' ? 'storage' : $minMetric,
                'runway_months' => $baseRunway,
            ],
            [
                'id' => 'increased_growth',
                'name' => 'increased_growth',
                'description' => 'Same trend with 25% higher growth rate (planning scenario).',
                'limiting_resource' => $minMetric === 'disk' ? 'storage' : $minMetric,
                'runway_months' => round($baseRunway / 1.25, 1),
            ],
            [
                'id' => 'optimization_applied',
                'name' => 'optimization_applied',
                'description' => 'Projected runway if utilization is reduced by 10% through optimization.',
                'limiting_resource' => $minMetric === 'disk' ? 'storage' : $minMetric,
                'runway_months' => $this->runwayMonths($this->reduceSeries($dailySeries[$minMetric === 'storage' ? 'disk' : $minMetric] ?? [], 0.9)),
            ],
        ];
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     * @return list<array{timestamp: int, value: float}>
     */
    private function reduceSeries(array $series, float $factor): array
    {
        return array_map(fn ($p) => [
            'timestamp' => $p['timestamp'],
            'value' => round($p['value'] * $factor, 2),
        ], $series);
    }

    /**
     * @param  array<string, array{host: string, cpu: ?float, memory: ?float, disk: ?float}>  $latestByHost
     * @return array{summary: string, bullets: list<string>}|null
     */
    private function buildAdvisorSummary(
        ?float $cpuRunway,
        ?float $memoryRunway,
        ?float $storageRunway,
        ?float $riskScore,
        int $insightCount,
        array $latestByHost
    ): ?array {
        if (empty($latestByHost) && $cpuRunway === null && $memoryRunway === null && $storageRunway === null) {
            return null;
        }

        $bullets = [];
        if ($cpuRunway !== null) {
            $bullets[] = 'CPU runway: ' . $cpuRunway . ' months at current growth.';
        }
        if ($memoryRunway !== null) {
            $bullets[] = 'Memory runway: ' . $memoryRunway . ' months at current growth.';
        }
        if ($storageRunway !== null) {
            $bullets[] = 'Storage runway: ' . $storageRunway . ' months at current growth.';
        }
        if ($riskScore !== null) {
            $bullets[] = 'Capacity risk score: ' . $riskScore . '/100.';
        }
        if ($insightCount > 0) {
            $bullets[] = $insightCount . ' optimization insight(s) require attention.';
        }

        if (empty($bullets)) {
            return null;
        }

        return [
            'summary' => 'Capacity posture derived from monitored host metrics and historical trends.',
            'bullets' => $bullets,
        ];
    }
}

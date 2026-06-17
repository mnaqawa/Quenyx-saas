<?php

namespace App\Services;

use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise capacity planning derived from native observe metrics history.
 * No fabricated business metrics or currency amounts.
 */
class CapacityPlanningService
{
    private const THRESHOLD_CRITICAL_MONTHS = 3.0;

    private const THRESHOLD_WARNING_MONTHS = 6.0;

    private const MIN_POINTS_FOR_RUNWAY = 5;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(int $workspaceId, string $range = '30d', array $options = []): array
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

        $historyPoints = $rows->count();
        $dailySeries = $this->buildDailySeries($rows, $bucketSeconds);
        $hostSeries = $this->buildHostMetricSeries($rows, $workspacePrefix);
        $latestByHost = $this->latestByHostMetric($rows, $workspacePrefix);
        $hostConsumers = $this->buildConsumerLists($latestByHost);

        $cpuRunway = $this->runwayMonths($dailySeries['cpu'] ?? []);
        $memoryRunway = $this->runwayMonths($dailySeries['memory'] ?? []);
        $storageRunway = $this->runwayMonths($dailySeries['disk'] ?? []);

        $cpuRunwayDays = $this->runwayDays($dailySeries['cpu'] ?? []);
        $memoryRunwayDays = $this->runwayDays($dailySeries['memory'] ?? []);
        $storageRunwayDays = $this->runwayDays($dailySeries['disk'] ?? []);

        $latestCpu = $this->latestAverage($dailySeries['cpu'] ?? []);
        $latestMemory = $this->latestAverage($dailySeries['memory'] ?? []);
        $latestDisk = $this->latestAverage($dailySeries['disk'] ?? []);

        $riskScore = $this->capacityRiskScore($latestCpu, $latestMemory, $latestDisk, $cpuRunway, $memoryRunway, $storageRunway);
        $shortestRunwayDays = $this->shortestRunwayDays([$cpuRunwayDays, $memoryRunwayDays, $storageRunwayDays]);
        $dataConfidence = $this->dataConfidence($historyPoints, $cpuRunway !== null || $memoryRunway !== null || $storageRunway !== null);
        $healthStatus = $this->healthStatus($riskScore, $shortestRunwayDays, true);
        $primaryRisk = $this->primaryRisk($latestCpu, $latestMemory, $latestDisk, $cpuRunwayDays, $memoryRunwayDays, $storageRunwayDays);
        $recommendedAction = $this->recommendedAction($healthStatus, $primaryRisk, $riskScore);

        $forecast = $this->buildForecastSeries($dailySeries);
        $growthTrends = $this->buildGrowthTrends($dailySeries);
        $topRisks = $this->buildTopRisks($hostSeries, $latestByHost);
        $insights = $this->buildOptimizationInsights($latestByHost, $hostSeries, $dailySeries, $workspaceId);
        $scenarioTemplates = $this->scenarioTemplates();
        $calculatedScenarios = $this->calculateScenarios(
            $dailySeries,
            $cpuRunway,
            $memoryRunway,
            $storageRunway,
            $cpuRunwayDays,
            $memoryRunwayDays,
            $storageRunwayDays,
            $riskScore,
            $options
        );
        $advisor = $this->buildStructuredAdvisor(
            $riskScore,
            $healthStatus,
            $primaryRisk,
            $recommendedAction,
            $dataConfidence,
            $insights,
            $topRisks,
            $cpuRunway,
            $memoryRunway,
            $storageRunway,
            $historyPoints
        );
        $budget = $this->buildBudgetForecast(
            $dailySeries,
            $cpuRunwayDays,
            $memoryRunwayDays,
            $storageRunwayDays,
            $growthTrends
        );

        $dataAvailable = ! empty($forecast) || ! empty($topRisks) || $advisor['available'];

        $health = [
            'health_status' => $healthStatus,
            'risk_score' => $riskScore,
            'shortest_runway_days' => $shortestRunwayDays,
            'primary_risk' => $primaryRisk,
            'recommended_action' => $recommendedAction,
            'data_confidence' => $dataConfidence,
        ];

        $runway = [
            'cpu' => ['months' => $cpuRunway, 'days' => $cpuRunwayDays, 'status' => $this->runwayStatus($cpuRunway)],
            'memory' => ['months' => $memoryRunway, 'days' => $memoryRunwayDays, 'status' => $this->runwayStatus($memoryRunway)],
            'storage' => ['months' => $storageRunway, 'days' => $storageRunwayDays, 'status' => $this->runwayStatus($storageRunway)],
        ];

        $legacySummary = [
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
            'health_status' => $healthStatus,
            'shortest_runway_days' => $shortestRunwayDays,
            'primary_risk' => $primaryRisk,
            'recommended_action' => $recommendedAction,
            'data_confidence' => $dataConfidence,
        ];

        $legacyAdvisor = $advisor['available'] ? [
            'summary' => $advisor['findings'][0] ?? '',
            'bullets' => array_merge($advisor['findings'], $advisor['recommended_actions']),
        ] : null;

        return [
            'health' => $health,
            'runway' => $runway,
            'forecast' => $forecast,
            'top_risks' => $topRisks,
            'resource_consumers' => $hostConsumers,
            'optimization_insights' => $insights,
            'scenarios' => [
                'templates' => $scenarioTemplates,
                'calculated' => $calculatedScenarios,
            ],
            'budget' => $budget,
            'advisor' => $advisor,
            'summary' => $legacySummary,
            'overview' => [
                'forecast' => $forecast,
                'growth_trends' => $growthTrends,
                'advisor' => $legacyAdvisor,
            ],
            'resource_analysis' => [
                'top_cpu_consumers' => $hostConsumers['top_cpu_consumers'],
                'top_memory_consumers' => $hostConsumers['top_memory_consumers'],
                'top_storage_consumers' => $hostConsumers['top_storage_consumers'],
                'distribution' => $hostConsumers['distribution'],
                'top_risks' => $topRisks,
            ],
            'scenario_planning' => $calculatedScenarios,
            'budget_planning' => [
                'current_monthly_cost' => null,
                'forecasted_cost' => [],
                'budget_variance' => null,
                'saving_opportunities' => [],
                'provider_breakdown' => [],
                'forecasted_requirements' => $budget['forecasted_requirements'],
                'cost_estimate_available' => $budget['cost_estimate_available'],
                'billing_integration_status' => $budget['billing_integration_status'],
            ],
            'meta' => [
                'data_available' => $dataAvailable,
                'last_updated' => $to->toIso8601String(),
                'range' => $range,
                'history_points' => $historyPoints,
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
        $health = [
            'health_status' => 'no_data',
            'risk_score' => null,
            'shortest_runway_days' => null,
            'primary_risk' => null,
            'recommended_action' => null,
            'data_confidence' => 'no_data',
        ];

        $runway = [
            'cpu' => ['months' => null, 'days' => null, 'status' => 'insufficient_data'],
            'memory' => ['months' => null, 'days' => null, 'status' => 'insufficient_data'],
            'storage' => ['months' => null, 'days' => null, 'status' => 'insufficient_data'],
        ];

        $budget = $this->emptyBudget();

        $advisor = [
            'available' => false,
            'findings' => [],
            'business_impact' => [],
            'recommended_actions' => [],
            'confidence' => 'no_data',
            'data_used' => [],
        ];

        $summary = [
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
            ...$health,
        ];

        return [
            'health' => $health,
            'runway' => $runway,
            'forecast' => [],
            'top_risks' => [],
            'resource_consumers' => [
                'top_cpu_consumers' => [],
                'top_memory_consumers' => [],
                'top_storage_consumers' => [],
                'distribution' => [],
            ],
            'optimization_insights' => [],
            'scenarios' => ['templates' => $this->scenarioTemplates(), 'calculated' => []],
            'budget' => $budget,
            'advisor' => $advisor,
            'summary' => $summary,
            'overview' => ['forecast' => [], 'growth_trends' => [], 'advisor' => null],
            'resource_analysis' => [
                'top_cpu_consumers' => [],
                'top_memory_consumers' => [],
                'top_storage_consumers' => [],
                'distribution' => [],
                'top_risks' => [],
            ],
            'scenario_planning' => [],
            'budget_planning' => [
                'current_monthly_cost' => null,
                'forecasted_cost' => [],
                'budget_variance' => null,
                'saving_opportunities' => [],
                'provider_breakdown' => [],
                'forecasted_requirements' => $budget['forecasted_requirements'],
                'cost_estimate_available' => false,
                'billing_integration_status' => 'not_connected',
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
     * @return array<string, mixed>
     */
    private function emptyBudget(): array
    {
        return [
            'forecasted_requirements' => [
                'cpu' => null,
                'memory' => null,
                'storage' => null,
                'timeline_days' => null,
            ],
            'cost_estimate_available' => false,
            'billing_integration_status' => 'not_connected',
        ];
    }

    private function healthStatus(?float $riskScore, ?float $shortestRunwayDays, bool $hasData): string
    {
        if (! $hasData) {
            return 'no_data';
        }
        if ($riskScore === null && $shortestRunwayDays === null) {
            return 'no_data';
        }
        $risk = $riskScore ?? 0;
        $runway = $shortestRunwayDays ?? PHP_FLOAT_MAX;
        if ($risk >= 80 || $runway <= 14) {
            return 'critical';
        }
        if ($risk >= 60 || $runway <= 30) {
            return 'risk';
        }
        if ($risk >= 35 || $runway <= 90) {
            return 'watch';
        }

        return 'healthy';
    }

    private function dataConfidence(int $historyPoints, bool $hasRunway): string
    {
        if ($historyPoints === 0) {
            return 'no_data';
        }
        if (! $hasRunway || $historyPoints < self::MIN_POINTS_FOR_RUNWAY) {
            return 'low';
        }
        if ($historyPoints < 50) {
            return 'medium';
        }

        return 'high';
    }

    /**
     * @param  array<float|null>  $runwayDaysList
     */
    private function shortestRunwayDays(array $runwayDaysList): ?float
    {
        $valid = array_filter($runwayDaysList, fn ($v) => $v !== null);
        if (empty($valid)) {
            return null;
        }

        return min($valid);
    }

    private function primaryRisk(
        ?float $cpu,
        ?float $memory,
        ?float $disk,
        ?float $cpuDays,
        ?float $memoryDays,
        ?float $storageDays
    ): ?string {
        $candidates = [];
        if ($cpu !== null) {
            $candidates['cpu'] = ['util' => $cpu, 'days' => $cpuDays];
        }
        if ($memory !== null) {
            $candidates['memory'] = ['util' => $memory, 'days' => $memoryDays];
        }
        if ($disk !== null) {
            $candidates['storage'] = ['util' => $disk, 'days' => $storageDays];
        }
        if (empty($candidates)) {
            return null;
        }

        $keys = array_keys($candidates);
        usort($keys, function ($ka, $kb) use ($candidates) {
            $a = $candidates[$ka];
            $b = $candidates[$kb];
            $scoreA = ($a['util'] * 0.5) + (($a['days'] !== null ? max(0, 90 - $a['days']) : 50) * 0.5);
            $scoreB = ($b['util'] * 0.5) + (($b['days'] !== null ? max(0, 90 - $b['days']) : 50) * 0.5);

            return $scoreB <=> $scoreA;
        });

        return $keys[0] ?? null;
    }

    private function recommendedAction(string $healthStatus, ?string $primaryRisk, ?float $riskScore): ?string
    {
        if ($healthStatus === 'no_data') {
            return null;
        }
        if ($healthStatus === 'critical') {
            return 'Immediate scaling or workload redistribution required for ' . ($primaryRisk ?? 'critical resources') . '.';
        }
        if ($healthStatus === 'risk') {
            return 'Plan capacity expansion for ' . ($primaryRisk ?? 'at-risk resources') . ' within 30 days.';
        }
        if ($healthStatus === 'watch') {
            return 'Monitor ' . ($primaryRisk ?? 'resource trends') . ' and validate growth assumptions weekly.';
        }

        return 'Maintain current capacity posture; continue scheduled monitoring.';
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
     * @return array<string, array<string, list<array{timestamp: int, value: float, recorded_at: string}>>>
     */
    private function buildHostMetricSeries($rows, string $workspacePrefix): array
    {
        $out = [];
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
            $out[$displayHost][$metric][] = [
                'timestamp' => $recordedAt->getTimestamp(),
                'value' => round((float) $row->value, 2),
                'recorded_at' => $recordedAt->toIso8601String(),
            ];
        }

        return $out;
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
                $byHost[$displayHost] = ['host' => $displayHost, 'cpu' => null, 'memory' => null, 'disk' => null, '_ts' => [], '_last_at' => []];
            }
            $prevTs = $byHost[$displayHost]['_ts'][$metric] ?? 0;
            if ($ts >= $prevTs) {
                $byHost[$displayHost]['_ts'][$metric] = $ts;
                $byHost[$displayHost][$metric] = round((float) $row->value, 2);
                $byHost[$displayHost]['_last_at'][$metric] = $recordedAt->toIso8601String();
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
     * @param  array<string, array<string, list<array{timestamp: int, value: float, recorded_at: string}>>>  $hostSeries
     * @param  array<string, array{host: string, cpu: ?float, memory: ?float, disk: ?float}>  $latestByHost
     * @return list<array<string, mixed>>
     */
    private function buildTopRisks(array $hostSeries, array $latestByHost): array
    {
        $risks = [];
        $metricMap = ['cpu' => 'cpu', 'memory' => 'memory', 'disk' => 'storage'];

        foreach ($hostSeries as $host => $metrics) {
            foreach ($metricMap as $src => $label) {
                $points = $metrics[$src] ?? [];
                if (count($points) < 2) {
                    continue;
                }
                $series = array_map(fn ($p) => ['timestamp' => $p['timestamp'], 'value' => $p['value']], $points);
                $current = $series[count($series) - 1]['value'];
                $runwayDays = $this->runwayDays($series);
                $reg = $this->linearRegression($series);
                $trend = 'unknown';
                if ($reg !== null) {
                    $slope = $reg[0];
                    if ($slope > 0.05) {
                        $trend = 'up';
                    } elseif ($slope < -0.05) {
                        $trend = 'down';
                    } else {
                        $trend = 'flat';
                    }
                }
                $riskLevel = $this->resourceRiskLevel($current, $runwayDays);
                $lastSample = $points[count($points) - 1]['recorded_at'] ?? null;
                $risks[] = [
                    'host' => $host,
                    'resource' => $label,
                    'utilization_pct' => $current,
                    'trend' => $trend,
                    'runway_days' => $runwayDays,
                    'risk_level' => $riskLevel,
                    'last_sample_at' => $lastSample,
                ];
            }
        }

        usort($risks, function ($a, $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'healthy' => 2, 'insufficient_data' => 3];
            $ra = $rank[$a['risk_level']] ?? 4;
            $rb = $rank[$b['risk_level']] ?? 4;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $da = $a['runway_days'] ?? PHP_FLOAT_MAX;
            $db = $b['runway_days'] ?? PHP_FLOAT_MAX;
            if ($da !== $db) {
                return $da <=> $db;
            }

            return ($b['utilization_pct'] ?? 0) <=> ($a['utilization_pct'] ?? 0);
        });

        return array_slice($risks, 0, 50);
    }

    private function resourceRiskLevel(float $util, ?float $runwayDays): string
    {
        if ($util >= 90 || ($runwayDays !== null && $runwayDays <= 14)) {
            return 'critical';
        }
        if ($util >= 75 || ($runwayDays !== null && $runwayDays <= 30)) {
            return 'warning';
        }
        if ($util >= 60 || ($runwayDays !== null && $runwayDays <= 90)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     */
    private function runwayMonths(array $series): ?float
    {
        $days = $this->runwayDays($series);

        return $days === null ? null : round($days / 30.4375, 1);
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     */
    private function runwayDays(array $series): ?float
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

        return round((100 - $current) / $slopePerDay, 1);
    }

    /**
     * @param  list<array{timestamp: int, value: float}>  $series
     * @return array{0: float, 1: float}|null
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

        return [$num / $den, $ys[$n - 1]];
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
            $runwayScore = match (true) {
                $minRunway <= 1 => 100.0,
                $minRunway <= 3 => 80.0,
                $minRunway <= 6 => 55.0,
                $minRunway <= 12 => 30.0,
                default => 10.0,
            };
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
        $allTimestamps = [];
        foreach (['cpu', 'memory', 'disk'] as $metric) {
            foreach ($dailySeries[$metric] ?? [] as $point) {
                $allTimestamps[$point['timestamp']] = true;
            }
        }
        ksort($allTimestamps);
        foreach (array_keys($allTimestamps) as $ts) {
            $points[] = [
                'time' => Carbon::createFromTimestamp($ts)->toIso8601String(),
                'label' => Carbon::createFromTimestamp($ts)->format('M d'),
                'cpu' => $this->valueAtTimestamp($dailySeries['cpu'] ?? [], $ts),
                'memory' => $this->valueAtTimestamp($dailySeries['memory'] ?? [], $ts),
                'storage' => $this->valueAtTimestamp($dailySeries['disk'] ?? [], $ts),
                'projected' => false,
            ];
        }
        foreach ($this->projectForward($dailySeries, 30) as $row) {
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
            $reg = $this->linearRegression($series);

            $trends[] = [
                'metric' => $label,
                'start_pct' => $first,
                'end_pct' => $last,
                'change_pct' => round($last - $first, 2),
                'monthly_growth_pct' => $reg ? round($reg[0] * 30.4375, 2) : null,
            ];
        }

        return $trends;
    }

    /**
     * @param  array<string, array{host: string, cpu: ?float, memory: ?float, disk: ?float}>  $latestByHost
     * @param  array<string, array<string, list<array{timestamp: int, value: float, recorded_at: string}>>>  $hostSeries
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @return list<array<string, mixed>>
     */
    private function buildOptimizationInsights(
        array $latestByHost,
        array $hostSeries,
        array $dailySeries,
        int $workspaceId
    ): array {
        $insights = [];
        $now = now()->toIso8601String();
        $costUnavailable = 'Cost estimate unavailable — connect billing integration.';

        foreach ($latestByHost as $host) {
            foreach (['cpu' => ['warn' => 70, 'crit' => 85], 'memory' => ['warn' => 75, 'crit' => 90], 'disk' => ['warn' => 80, 'crit' => 92]] as $metric => $thresholds) {
                $val = $host[$metric] ?? null;
                if ($val === null) {
                    continue;
                }
                if ($val >= $thresholds['warn']) {
                    $severity = $val >= $thresholds['crit'] ? 'high' : 'medium';
                    $insights[] = $this->insightRow(
                        'high_utilization',
                        'host-' . $host['host'] . '-' . $metric . '-util',
                        $severity,
                        $host['host'],
                        $metric === 'disk' ? 'storage' : $metric,
                        'Utilization at ' . $val . '% on latest sample.',
                        $severity === 'high' ? 'Scale or redistribute workload before limits are reached.' : 'Review workload and plan scaling within the growth window.',
                        'Reduces degradation risk on ' . $host['host'],
                        $now
                    );
                } elseif ($val <= 20 && count($hostSeries[$host['host']][$metric] ?? []) >= self::MIN_POINTS_FOR_RUNWAY) {
                    $avg = array_sum(array_column($hostSeries[$host['host']][$metric], 'value')) / count($hostSeries[$host['host']][$metric]);
                    if ($avg <= 25) {
                        $insights[] = $this->insightRow(
                            'underutilized',
                            'host-' . $host['host'] . '-' . $metric . '-under',
                            'low',
                            $host['host'],
                            $metric === 'disk' ? 'storage' : $metric,
                            'Sustained low utilization (' . round($avg, 1) . '% average).',
                            'Consider consolidating workloads or downsizing if pattern persists.',
                            'Improves resource efficiency on ' . $host['host'],
                            $now
                        );
                    }
                }
            }
        }

        foreach (['cpu' => 'cpu', 'memory' => 'memory', 'disk' => 'storage'] as $src => $label) {
            $series = $dailySeries[$src] ?? [];
            if (count($series) < self::MIN_POINTS_FOR_RUNWAY) {
                continue;
            }
            $reg = $this->linearRegression($series);
            if ($reg !== null && $reg[0] > 0.15) {
                $monthly = round($reg[0] * 30.4375, 2);
                $insights[] = $this->insightRow(
                    'fast_growth',
                    'growth-' . $label,
                    $monthly > 8 ? 'high' : 'medium',
                    'workspace',
                    $label,
                    'Observed growth trend of ~' . $monthly . '% per month.',
                    'Increase monitoring frequency and validate scaling plans for ' . $label . '.',
                    'Earlier intervention before capacity limits.',
                    $now
                );
            }
            $runwayDays = $this->runwayDays($series);
            if ($runwayDays !== null && $runwayDays <= 90) {
                $insights[] = $this->insightRow(
                    'short_runway',
                    'runway-' . $label,
                    $runwayDays <= 30 ? 'high' : 'medium',
                    'workspace',
                    $label,
                    'Projected runway of ' . $runwayDays . ' days at current growth.',
                    'Provision additional ' . $label . ' capacity before runway expires.',
                    'Prevents service disruption from resource exhaustion.',
                    $now
                );
            }
        }

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

        foreach ($criticalServices as $svc) {
            $host = preg_replace('/^ws\d+-/', '', (string) $svc->host_name);
            $insights[] = $this->insightRow(
                'failed_check',
                'svc-' . $svc->host_name . '-' . $svc->service_name,
                $svc->state === 'critical' ? 'high' : 'medium',
                $host . ' / ' . $svc->service_name,
                'monitoring',
                ucfirst((string) $svc->state) . ' check: ' . mb_substr((string) ($svc->output ?? ''), 0, 120),
                'Investigate failing capacity-related check and remediate root cause.',
                'Restores reliable monitoring signal for capacity decisions.',
                $svc->last_check_at?->toIso8601String() ?? $now
            );
        }

        usort($insights, fn ($a, $b) => ($a['severity'] === 'high' ? 0 : ($a['severity'] === 'medium' ? 1 : 2))
            <=> ($b['severity'] === 'high' ? 0 : ($b['severity'] === 'medium' ? 1 : 2)));

        return array_slice($insights, 0, 25);
    }

    /**
     * @return array<string, mixed>
     */
    private function insightRow(
        string $type,
        string $id,
        string $severity,
        string $affected,
        string $resource,
        string $evidence,
        string $action,
        string $impact,
        string $createdAt
    ): array {
        $priority = $severity === 'high' ? 'high' : ($severity === 'medium' ? 'medium' : 'low');

        return [
            'id' => $id,
            'type' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type)) . ' — ' . $affected,
            'severity' => $severity,
            'priority' => $priority,
            'affected_resource' => $affected,
            'resource' => $resource,
            'evidence' => $evidence,
            'issue' => $evidence,
            'recommendation' => $action,
            'recommended_action' => $action,
            'expected_impact' => $impact,
            'operational_impact' => $impact,
            'cost_impact_status' => 'unavailable',
            'cost_impact_message' => 'Cost estimate unavailable — connect billing integration.',
            'estimated_saving' => null,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scenarioTemplates(): array
    {
        return [
            ['id' => 'traffic_growth', 'name' => 'traffic_growth', 'default_growth_pct' => 25, 'default_horizon_days' => 90, 'default_resource' => 'cpu'],
            ['id' => 'tenant_growth', 'name' => 'tenant_growth', 'default_growth_pct' => 30, 'default_horizon_days' => 180, 'default_resource' => 'memory'],
            ['id' => 'site_expansion', 'name' => 'site_expansion', 'default_growth_pct' => 40, 'default_horizon_days' => 365, 'default_resource' => 'cpu'],
            ['id' => 'storage_growth', 'name' => 'storage_growth', 'default_growth_pct' => 20, 'default_horizon_days' => 180, 'default_resource' => 'storage'],
            ['id' => 'custom', 'name' => 'custom', 'default_growth_pct' => 15, 'default_horizon_days' => 90, 'default_resource' => 'cpu'],
        ];
    }

    /**
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function calculateScenarios(
        array $dailySeries,
        ?float $cpuRunway,
        ?float $memoryRunway,
        ?float $storageRunway,
        ?float $cpuRunwayDays,
        ?float $memoryRunwayDays,
        ?float $storageRunwayDays,
        ?float $riskScore,
        array $options
    ): array {
        $runways = ['cpu' => $cpuRunway, 'memory' => $memoryRunway, 'storage' => $storageRunway];
        $runwayDays = ['cpu' => $cpuRunwayDays, 'memory' => $memoryRunwayDays, 'storage' => $storageRunwayDays];
        $valid = array_filter($runways, fn ($v) => $v !== null);
        if (empty($valid)) {
            return [];
        }

        if (empty($options['scenario_template'])) {
            $results = [];
            foreach ($this->scenarioTemplates() as $template) {
                $results[] = $this->buildOneScenario(
                    (string) $template['id'],
                    $template,
                    $runways,
                    $runwayDays,
                    $riskScore,
                    null
                );
            }

            return $results;
        }

        $templateId = (string) $options['scenario_template'];
        $templates = collect($this->scenarioTemplates())->keyBy('id');
        $template = $templates->get($templateId, $templates->get('traffic_growth'));

        return [
            $this->buildOneScenario($templateId, $template, $runways, $runwayDays, $riskScore, $options),
        ];
    }

    /**
     * @param  array<string, float|null>  $runways
     * @param  array<string, float|null>  $runwayDays
     * @param  array<string, mixed>|null  $template
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildOneScenario(
        string $templateId,
        ?array $template,
        array $runways,
        array $runwayDays,
        ?float $riskScore,
        ?array $options
    ): array {
        $growthPct = isset($options['growth_pct'])
            ? (float) $options['growth_pct']
            : (float) ($template['default_growth_pct'] ?? 25);
        $horizonDays = isset($options['horizon_days'])
            ? (int) $options['horizon_days']
            : (int) ($template['default_horizon_days'] ?? 90);
        $targetResource = (string) ($options['target_resource'] ?? $template['default_resource'] ?? 'cpu');

        $baseRunway = $runways[$targetResource] ?? min(array_filter($runways, fn ($v) => $v !== null));
        $baseRunwayDays = $runwayDays[$targetResource] ?? null;
        $growthMultiplier = 1 + ($growthPct / 100);
        $projectedRunwayDays = $baseRunwayDays !== null ? round($baseRunwayDays / $growthMultiplier, 1) : null;
        $projectedRunwayMonths = $projectedRunwayDays !== null ? round($projectedRunwayDays / 30.4375, 1) : null;
        $riskChange = $riskScore !== null && $projectedRunwayDays !== null
            ? ($projectedRunwayDays < 30 ? 'increased' : ($projectedRunwayDays < 90 ? 'elevated' : 'stable'))
            : 'unknown';

        return [
            'id' => $templateId,
            'name' => $templateId,
            'template' => $templateId,
            'description' => 'Scenario based on ' . $growthPct . '% growth over ' . $horizonDays . ' days on ' . $targetResource . '.',
            'limiting_resource' => $targetResource,
            'growth_pct' => $growthPct,
            'horizon_days' => $horizonDays,
            'target_resource' => $targetResource,
            'current_runway_days' => $baseRunwayDays,
            'current_runway_months' => $baseRunway,
            'projected_runway_days' => $projectedRunwayDays,
            'projected_runway_months' => $projectedRunwayMonths,
            'risk_change' => $riskChange,
            'impact_summary' => $projectedRunwayDays !== null
                ? 'Projected runway decreases to ' . $projectedRunwayDays . ' days under this growth assumption.'
                : 'Scenario cannot be calculated due to insufficient historical data.',
            'calculable' => $projectedRunwayDays !== null,
            'runway_months' => $projectedRunwayMonths,
        ];
    }

    /**
     * @param  array<string, list<array{timestamp: int, value: float}>>  $dailySeries
     * @param  list<array<string, mixed>>  $growthTrends
     * @return array<string, mixed>
     */
    private function buildBudgetForecast(
        array $dailySeries,
        ?float $cpuRunwayDays,
        ?float $memoryRunwayDays,
        ?float $storageRunwayDays,
        array $growthTrends
    ): array {
        $additional = ['cpu' => null, 'memory' => null, 'storage' => null];
        foreach ($growthTrends as $trend) {
            $metric = $trend['metric'];
            $change = $trend['change_pct'] ?? 0;
            if ($change > 0) {
                $additional[$metric] = round($change, 1);
            }
        }

        $timeline = $this->shortestRunwayDays([$cpuRunwayDays, $memoryRunwayDays, $storageRunwayDays]);
        $hasForecast = array_filter($additional, fn ($v) => $v !== null) !== [] || $timeline !== null;

        return [
            'forecasted_requirements' => [
                'cpu' => $additional['cpu'],
                'memory' => $additional['memory'],
                'storage' => $additional['storage'],
                'timeline_days' => $timeline,
            ],
            'cost_estimate_available' => false,
            'billing_integration_status' => 'not_connected',
            'has_forecast' => $hasForecast,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $insights
     * @param  list<array<string, mixed>>  $topRisks
     * @return array<string, mixed>
     */
    private function buildStructuredAdvisor(
        ?float $riskScore,
        string $healthStatus,
        ?string $primaryRisk,
        ?string $recommendedAction,
        string $dataConfidence,
        array $insights,
        array $topRisks,
        ?float $cpuRunway,
        ?float $memoryRunway,
        ?float $storageRunway,
        int $historyPoints
    ): array {
        if ($historyPoints < self::MIN_POINTS_FOR_RUNWAY || $dataConfidence === 'no_data') {
            return [
                'available' => false,
                'findings' => [],
                'business_impact' => [],
                'recommended_actions' => [],
                'confidence' => 'no_data',
                'data_used' => [],
            ];
        }

        $findings = [];
        if ($riskScore !== null) {
            $findings[] = 'Capacity risk score is ' . $riskScore . '/100 (' . $healthStatus . ').';
        }
        if ($primaryRisk !== null) {
            $findings[] = 'Primary risk resource: ' . $primaryRisk . '.';
        }
        if ($cpuRunway !== null) {
            $findings[] = 'CPU runway: ' . $cpuRunway . ' months.';
        }
        if ($memoryRunway !== null) {
            $findings[] = 'Memory runway: ' . $memoryRunway . ' months.';
        }
        if ($storageRunway !== null) {
            $findings[] = 'Storage runway: ' . $storageRunway . ' months.';
        }
        if (! empty($topRisks)) {
            $top = $topRisks[0];
            $findings[] = 'Highest risk: ' . $top['host'] . ' ' . $top['resource'] . ' at ' . $top['utilization_pct'] . '%.';
        }

        $businessImpact = [];
        if (in_array($healthStatus, ['critical', 'risk'], true)) {
            $businessImpact[] = 'Elevated risk of service degradation or outage if growth continues unchecked.';
        } elseif ($healthStatus === 'watch') {
            $businessImpact[] = 'Capacity headroom is narrowing; planning actions should be scheduled.';
        } else {
            $businessImpact[] = 'Current monitored capacity posture is within acceptable bounds.';
        }

        $actions = [];
        if ($recommendedAction) {
            $actions[] = $recommendedAction;
        }
        foreach (array_slice($insights, 0, 3) as $insight) {
            $actions[] = $insight['recommended_action'] ?? $insight['recommendation'] ?? '';
        }
        $actions = array_values(array_filter(array_unique($actions)));

        return [
            'available' => ! empty($findings),
            'findings' => $findings,
            'business_impact' => $businessImpact,
            'recommended_actions' => $actions,
            'confidence' => $dataConfidence,
            'data_used' => [
                'history_samples' => $historyPoints,
                'resources' => array_filter(['cpu' => $cpuRunway !== null, 'memory' => $memoryRunway !== null, 'storage' => $storageRunway !== null]),
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\NmapPortScanJob;
use App\Models\HostPortScan;
use App\Models\ObserveService;
use App\Models\ObserveMeta;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\IntegrationConfiguration;
use App\Models\Agent;
use App\Models\AgentMetric;
use App\Models\BillingIntegration;
use App\Models\ObserveReportExport;
use App\Models\Project;
use App\Services\CapacityPlanningService;
use App\Services\SystemMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ObserveController extends Controller
{
    /**
     * Get active service definitions for capability-driven UI.
     * GET /api/workspaces/{project}/observe/service-definitions?engine=native&status=active
     */
    public function serviceDefinitions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        if (!class_exists(ObserveServiceDefinition::class) || !\Illuminate\Support\Facades\Schema::hasTable('observe_service_definitions')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $engine = trim((string) $request->query('engine', ''));
        $status = $request->query('status', 'active');

        $definitions = ObserveServiceDefinition::query()
            ->when($engine !== '', fn ($q) => $q->where('engine', $engine))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('service_key')
            ->get()
            ->map(fn ($d) => [
                'engine' => $d->engine,
                'service_key' => $d->service_key,
                'display_name' => $d->display_name,
                'description' => $d->description,
                'check_command' => $d->check_command,
                'args_schema' => $d->args_schema ?? [],
                'capability_flags' => $d->capability_flags ?? [],
                'status' => $d->status,
            ]);

        return response()->json([
            'success' => true,
            'data' => $definitions->values()->all(),
        ]);
    }
    /**
     * Get observe summary for a workspace
     */
    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $meta = ObserveMeta::where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->orderByDesc('last_poll_at')
            ->first();

        $prefix = 'ws' . $project->id . '-';
        $allServices = ObserveService::where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $prefix . '%')
            ->get();
        $totals = [
            'ok' => $allServices->where('state', 'ok')->count(),
            'warning' => $allServices->where('state', 'warning')->count(),
            'critical' => $allServices->where('state', 'critical')->count(),
            'unknown' => $allServices->where('state', 'unknown')->count(),
            'pending' => $allServices->where('state', 'pending')->count(),
            'unreachable' => $allServices->where('state', 'unreachable')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => $totals,
                'last_poll_at' => $meta?->last_poll_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get observe services for a workspace with filtering
     */
    public function services(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        // Get query parameters
        $q = $request->query('q');
        $statusParam = $request->query('status');
        $limit = (int) ($request->query('limit', 100));
        $problemsOnly = $request->query('problems') === '1' || $request->query('problemsOnly') === 'true';

        // Build query with workspace scoping (native monitoring data only)
        $workspacePrefix = 'ws' . $project->id . '-';
        $query = ObserveService::where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $workspacePrefix . '%');

        // Apply status filter
        if ($statusParam) {
            $statuses = is_array($statusParam) ? $statusParam : explode(',', $statusParam);
            $query->whereIn('state', array_map('trim', $statuses));
        }

        // Apply problems filter
        if ($problemsOnly) {
            $query->whereIn('state', ['warning', 'critical', 'unknown', 'unreachable']);
        }

        // Apply search query
        if ($q && trim($q)) {
            $searchTerm = trim($q);
            $query->where(function ($q) use ($searchTerm) {
                $q->where('host_name', 'like', "%{$searchTerm}%")
                    ->orWhere('service_name', 'like', "%{$searchTerm}%")
                    ->orWhere('output', 'like', "%{$searchTerm}%");
            });
        }

        // Fetch all matching services (native engine only)
        $deduped = $query->get()->values();

        // Sort by host name first (group by host), then by severity within each host
        $severityOrder = fn ($s) => match ($s->state) {
            'critical' => 1,
            'warning' => 2,
            'unknown' => 3,
            'unreachable' => 4,
            'pending' => 5,
            'ok' => 6,
            default => 7,
        };
        $sorted = $deduped->sortBy(fn ($s) => [$s->host_name, $severityOrder($s)]);
        $services = $sorted->take($limit)->values();

        // Totals from deduped set (same scope as list)
        $allServices = $deduped;

        $serviceTotals = [
            'ok' => $allServices->where('state', 'ok')->count(),
            'warning' => $allServices->where('state', 'warning')->count(),
            'critical' => $allServices->where('state', 'critical')->count(),
            'unknown' => $allServices->where('state', 'unknown')->count(),
            'pending' => $allServices->where('state', 'pending')->count(),
            'unreachable' => $allServices->where('state', 'unreachable')->count(),
        ];

        // Calculate host totals
        $hosts = $allServices->groupBy('host_name');
        $hostTotals = [
            'up' => $hosts->count(), // Simplified: assume all hosts are up if they have services
            'down' => 0,
            'unreachable' => 0,
            'pending' => 0,
        ];

        // Get meta for last_poll_at (native engine)
        $nativeMeta = ObserveMeta::where('workspace_id', $project->id)->where('engine_key', 'native')->first();

        $monitoringConfigured = ObserveTargetHost::where('workspace_id', $project->id)
            ->where('enabled', true)
            ->exists()
            || ObserveService::where('workspace_id', $project->id)
                ->where('engine_key', 'native')
                ->exists();

        // Normalized state code for UI (9 = UNREACHABLE per TPM)
        $stateCode = fn (string $state): int => match ($state) {
            'ok' => 0,
            'warning' => 1,
            'critical' => 2,
            'unknown' => 3,
            'pending' => 4,
            'unreachable' => 9,
            default => 4,
        };

        $items = $services->map(function ($service) use ($stateCode) {
            $output = $service->output ?? '';
            $pluginOutput = $service->plugin_output ?? '';
            $statusInfo = $output !== '' ? $output : $pluginOutput;
            return [
                'host' => $service->host_name,
                'service' => $service->service_name,
                'status' => $service->state,
                'state_code' => $stateCode($service->state),
                'lastCheckAt' => $service->last_check_at?->toIso8601String() ?? '',
                'nextCheckAt' => $service->next_check_at?->toIso8601String() ?? '',
                'durationSec' => $service->duration_sec ?? 0,
                'attempt' => $service->attempt ?? '1/3',
                'currentAttempt' => $service->current_attempt,
                'maxAttempts' => $service->max_attempts,
                'stateType' => $service->state_type ?? '',
                'info' => $statusInfo,
                'status_information' => $statusInfo,
                'pluginOutput' => $pluginOutput,
                'longPluginOutput' => $service->long_plugin_output ?? '',
                'perfData' => $service->perfdata ?? '',
                'checkCommand' => $service->check_command ?? '',
                'checkLatencySec' => $service->check_latency_sec,
                'executionTimeSec' => $service->execution_time_sec,
                'lastStateChangeAt' => $service->last_state_change_at?->toIso8601String() ?? '',
            ];
        })->toArray();

        $lastPollAt = $nativeMeta?->last_poll_at?->toIso8601String();
        // Only report unreachable from native engine (QynSight).
        $engineUnreachable = $nativeMeta && trim((string) ($nativeMeta->error ?? '')) !== '';
        $engineUnreachableReason = $engineUnreachable
            ? (trim((string) ($nativeMeta->error ?? '')) ?: 'Monitoring engine could not be reached.')
            : null;
        $staleThresholdSeconds = max(60, (int) config('observe.stale_threshold_seconds', 300));
        $sourceTimestamp = $lastPollAt;
        $pollAt = $nativeMeta?->last_poll_at;
        $stale = $monitoringConfigured
            && ! $engineUnreachable
            && ($pollAt === null || $pollAt->lt(now()->subSeconds($staleThresholdSeconds)));

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'hostTotals' => $hostTotals,
                    'serviceTotals' => $serviceTotals,
                    'items' => $items,
                    'last_poll_at' => $lastPollAt,
                    'source_timestamp' => $sourceTimestamp,
                    'engine_unreachable' => $engineUnreachable,
                    'engine_unreachable_reason' => $engineUnreachableReason,
                    'stale' => $stale,
                ],
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Trigger native monitoring checks for the workspace.
     * POST /api/workspaces/{project}/observe/run-checks
     */
    public function runChecks(Request $request, Project $project): JsonResponse
    {
        $this->authorize('runObserveOperations', $project);

        try {
            Artisan::call('observe:run-checks', ['--workspace_id' => (string) $project->id]);

            return response()->json([
                'success' => true,
                'message' => 'Monitoring checks completed.',
            ]);
        } catch (\Throwable $e) {
            Log::warning('observe run-checks API failed', [
                'workspace_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to run monitoring checks.',
            ], 500);
        }
    }

    /**
     * Historical performance metrics from native observe samples.
     * GET /api/workspaces/{project}/observe/performance/metrics?range=24h
     */
    public function performanceMetrics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        [$range, $from, $bucketSeconds] = $this->resolvePerformanceRange((string) $request->query('range', '24h'));
        $to = now();
        $workspacePrefix = 'ws' . $project->id . '-';

        if (! Schema::hasTable('observe_metrics_history')) {
            return response()
                ->json([
                    'success' => true,
                    'data' => [
                        'range' => $range,
                        'from' => $from->toIso8601String(),
                        'to' => $to->toIso8601String(),
                        'bucket_seconds' => $bucketSeconds,
                        'host_count' => 0,
                        'latest' => [
                            'cpu' => null,
                            'memory' => null,
                            'disk' => null,
                            'network' => null,
                        ],
                        'trends' => [],
                        'hosts' => [],
                    ],
                ])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        $rows = ObserveMetricHistory::query()
            ->where('workspace_id', $project->id)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<=', $to)
            ->whereIn('metric', ['cpu', 'memory', 'disk', 'network'])
            ->orderBy('recorded_at')
            ->get(['host_name', 'service_name', 'metric', 'value', 'recorded_at']);

        $bucketed = [];
        $latestByHostMetric = [];
        $hostNames = [];

        foreach ($rows as $row) {
            $recordedAt = $row->recorded_at;
            if ($recordedAt === null) {
                continue;
            }
            $metric = (string) $row->metric;
            $value = (float) $row->value;
            $hostName = (string) $row->host_name;
            $displayHost = str_starts_with($hostName, $workspacePrefix)
                ? substr($hostName, strlen($workspacePrefix))
                : $hostName;
            $hostNames[$displayHost] = true;

            $bucketTs = intdiv($recordedAt->getTimestamp(), $bucketSeconds) * $bucketSeconds;
            $bucketKey = (string) $bucketTs;
            $bucketed[$bucketKey]['timestamp'] = $bucketTs;
            $bucketed[$bucketKey][$metric][] = $value;

            $hostKey = $displayHost . '|' . $metric;
            $latestByHostMetric[$hostKey] = [
                'host' => $displayHost,
                'metric' => $metric,
                'value' => $value,
                'recorded_at' => $recordedAt->toIso8601String(),
            ];
        }

        ksort($bucketed);
        $trends = [];
        foreach ($bucketed as $bucket) {
            $point = [
                'time' => now()->setTimestamp($bucket['timestamp'])->toIso8601String(),
                'label' => now()->setTimestamp($bucket['timestamp'])->format($range === '30d' ? 'M d' : 'H:i'),
                'cpu' => $this->avgOrNull($bucket['cpu'] ?? []),
                'memory' => $this->avgOrNull($bucket['memory'] ?? []),
                'disk' => $this->avgOrNull($bucket['disk'] ?? []),
                'network' => $this->avgOrNull($bucket['network'] ?? []),
            ];
            $trends[] = $point;
        }

        $latest = [
            'cpu' => $this->latestMetricAverage($latestByHostMetric, 'cpu'),
            'memory' => $this->latestMetricAverage($latestByHostMetric, 'memory'),
            'disk' => $this->latestMetricAverage($latestByHostMetric, 'disk'),
            'network' => $this->latestMetricAverage($latestByHostMetric, 'network'),
        ];

        $hosts = [];
        foreach (array_keys($hostNames) as $hostName) {
            $hosts[] = [
                'name' => $hostName,
                'cpu' => $latestByHostMetric[$hostName . '|cpu']['value'] ?? null,
                'memory' => $latestByHostMetric[$hostName . '|memory']['value'] ?? null,
                'disk' => $latestByHostMetric[$hostName . '|disk']['value'] ?? null,
                'network' => $latestByHostMetric[$hostName . '|network']['value'] ?? null,
                'last_seen_at' => collect(['cpu', 'memory', 'disk', 'network'])
                    ->map(fn ($metric) => $latestByHostMetric[$hostName . '|' . $metric]['recorded_at'] ?? null)
                    ->filter()
                    ->sortDesc()
                    ->first(),
            ];
        }
        usort($hosts, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'range' => $range,
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                    'bucket_seconds' => $bucketSeconds,
                    'host_count' => count($hostNames),
                    'latest' => $latest,
                    'trends' => $trends,
                    'hosts' => $hosts,
                ],
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * @return array{0: string, 1: \Illuminate\Support\Carbon, 2: int}
     */
    private function resolvePerformanceRange(string $range): array
    {
        return match ($range) {
            '1h' => ['1h', now()->subHour(), 60],
            '6h' => ['6h', now()->subHours(6), 300],
            '7d' => ['7d', now()->subDays(7), 21600],
            '30d' => ['30d', now()->subDays(30), 86400],
            default => ['24h', now()->subDay(), 3600],
        };
    }

    /**
     * @param  array<int, float|int>  $values
     */
    private function avgOrNull(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param  array<string, array{host: string, metric: string, value: float, recorded_at: string}>  $latestByHostMetric
     */
    private function latestMetricAverage(array $latestByHostMetric, string $metric): ?float
    {
        $values = [];
        foreach ($latestByHostMetric as $item) {
            if (($item['metric'] ?? '') === $metric) {
                $values[] = (float) $item['value'];
            }
        }

        return $this->avgOrNull($values);
    }

    /**
     * Capacity planning payload derived from observe metrics history.
     * GET /api/workspaces/{project}/observe/capacity-planning?range=30d
     */
    public function capacityPlanning(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $range = (string) $request->query('range', '30d');
        $options = array_filter([
            'scenario_template' => $request->query('scenario_template'),
            'growth_pct' => $request->query('growth_pct'),
            'horizon_days' => $request->query('horizon_days'),
            'target_resource' => $request->query('target_resource'),
            'hosts' => $request->query('hosts'),
        ], fn ($v) => $v !== null && $v !== '');
        $data = app(CapacityPlanningService::class)->build($project->id, $range, $options);

        return response()
            ->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * JSON export for capacity planning report.
     * GET /api/workspaces/{project}/observe/capacity-planning/export?range=30d&format=json
     */
    public function capacityPlanningExport(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $format = (string) $request->query('format', 'json');
        if ($format !== 'json') {
            return response()->json([
                'success' => false,
                'message' => 'Only JSON export is supported in this release.',
            ], 400);
        }

        $range = (string) $request->query('range', '30d');
        $options = array_filter([
            'scenario_template' => $request->query('scenario_template'),
            'growth_pct' => $request->query('growth_pct'),
            'horizon_days' => $request->query('horizon_days'),
            'target_resource' => $request->query('target_resource'),
            'hosts' => $request->query('hosts'),
        ], fn ($v) => $v !== null && $v !== '');

        $report = app(CapacityPlanningService::class)->buildExport($project->id, $range, $options);
        $filename = 'capacity-planning-ws' . $project->id . '-' . $range . '.json';
        $json = json_encode($report);
        $size = $json !== false ? strlen($json) : null;

        if (Schema::hasTable('observe_report_exports')) {
            ObserveReportExport::create([
                'workspace_id' => $project->id,
                'export_type' => 'capacity_planning',
                'format' => 'json',
                'title' => 'Capacity Planning (' . $range . ')',
                'file_size_bytes' => $size,
                'exported_by' => $request->user()?->id,
                'created_at' => now(),
            ]);
        }

        return response()
            ->json($report)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Legacy alias — returns summary KPIs from capacity planning payload.
     */
    public function capacityMetrics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $range = (string) $request->query('range', '30d');
        $payload = app(CapacityPlanningService::class)->build($project->id, $range);
        $summary = $payload['summary'] ?? [];

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /**
     * Alert rules — delegated to ObserveAlertController (kept for route compatibility).
     */
    public function alertRules(Request $request, Project $project): JsonResponse
    {
        return app(ObserveAlertController::class)->rules($request, $project);
    }

    public function alertSummary(Request $request, Project $project): JsonResponse
    {
        return app(ObserveAlertController::class)->summary($request, $project);
    }

    /**
     * Stub: instances list (no backend implementation yet). Returns empty array.
     */
    public function instances(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: instances summary (no backend implementation yet). Returns empty summary.
     */
    public function instanceSummary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json([
            'success' => true,
            'data' => [
                'total' => 0,
                'running' => 0,
                'warning' => 0,
                'avgCpuUsage' => 0,
            ],
        ]);
    }

    /**
     * JSON export history for this workspace.
     */
    public function reports(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        if (! Schema::hasTable('observe_report_exports')) {
            return response()->json(['success' => true, 'data' => [], 'meta' => ['backend_available' => false]]);
        }

        $items = ObserveReportExport::where('workspace_id', $project->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ObserveReportExport $r) => [
                'id' => (string) $r->id,
                'name' => $r->title,
                'category' => $r->export_type,
                'date' => $r->created_at?->toIso8601String() ?? '',
                'size' => $r->file_size_bytes ? $this->formatBytes($r->file_size_bytes) : '—',
                'status' => 'completed',
                'format' => $r->format,
            ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => ['backend_available' => true],
        ]);
    }

    public function reportSummary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $backendAvailable = Schema::hasTable('observe_report_exports');
        $total = $backendAvailable
            ? ObserveReportExport::where('workspace_id', $project->id)->count()
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'downloads' => 0,
                'scheduled' => 0,
                'avgSize' => '—',
                'backend_available' => $backendAvailable,
            ],
        ]);
    }

    /**
     * Connected data sources derived from real integrations (agents, billing, native observe).
     */
    public function dataSources(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => $this->buildDataSourcesList($project),
        ]);
    }

    public function dataSourceSummary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $sources = $this->buildDataSourcesList($project);
        $connected = count(array_filter($sources, fn ($s) => $s['status'] === 'connected'));
        $totalRecords = array_sum(array_column($sources, 'recordCount'));
        $lastSyncAt = collect($sources)->pluck('lastSyncAt')->filter()->max();
        $total = count($sources);
        $syncStatus = $total > 0 ? (int) round(($connected / $total) * 100) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'connected' => $connected,
                'totalRecords' => (string) $totalRecords,
                'syncStatus' => $syncStatus,
                'lastUpdate' => $lastSyncAt ? \Carbon\Carbon::parse($lastSyncAt)->diffForHumans() : '—',
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDataSourcesList(Project $project): array
    {
        $sources = [];

        if (Schema::hasTable('agents')) {
            $agents = Agent::where('workspace_id', $project->id)->orderBy('hostname')->get();
            foreach ($agents as $agent) {
                $metricCount = Schema::hasTable('agent_metrics')
                    ? AgentMetric::where('agent_id', $agent->id)->count()
                    : 0;
                $status = match ($agent->status) {
                    'online' => 'connected',
                    'error' => 'error',
                    default => 'syncing',
                };
                $sources[] = [
                    'id' => 'agent-' . $agent->id,
                    'name' => $agent->hostname,
                    'type' => 'agent_metrics',
                    'recordCount' => $metricCount,
                    'lastSync' => $agent->last_seen_at?->diffForHumans() ?? '—',
                    'lastSyncAt' => $agent->last_seen_at?->toIso8601String(),
                    'status' => $status,
                ];
            }
        }

        if (Schema::hasTable('billing_integrations')) {
            $billing = BillingIntegration::where('workspace_id', $project->id)->get();
            foreach ($billing as $b) {
                $connected = in_array($b->status, ['connected', 'active'], true);
                $sources[] = [
                    'id' => 'billing-' . $b->id,
                    'name' => ucfirst(str_replace('_', ' ', $b->provider_type)) . ' Billing',
                    'type' => 'billing_feed',
                    'recordCount' => 0,
                    'lastSync' => $b->connected_at?->diffForHumans() ?? '—',
                    'lastSyncAt' => $b->connected_at?->toIso8601String(),
                    'status' => $connected ? 'connected' : 'error',
                ];
            }
        }

        $meta = ObserveMeta::where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->orderByDesc('last_poll_at')
            ->first();

        if ($meta?->last_poll_at) {
            $hostCount = ObserveTargetHost::where('workspace_id', $project->id)->count();
            $sources[] = [
                'id' => 'native-observe',
                'name' => 'QynSight Native Checks',
                'type' => 'topology_feed',
                'recordCount' => $hostCount,
                'lastSync' => $meta->last_poll_at->diffForHumans(),
                'lastSyncAt' => $meta->last_poll_at->toIso8601String(),
                'status' => 'connected',
            ];
        }

        return $sources;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * Real-time system metrics (CPU, memory, disk, network, temperature) from the server.
     */
    public function realTimeMetrics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        try {
            $data = app(SystemMetricsService::class)->getRealTimeMetrics();
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::warning('ObserveController::realTimeMetrics failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [
                'cpu' => ['value' => 0, 'cores' => '—', 'frequency' => '—'],
                'memory' => ['value' => 0, 'used' => '—', 'total' => '—'],
                'diskIO' => ['value' => 0, 'type' => '—', 'throughput' => '—'],
                'network' => ['value' => 0, 'speed' => '—', 'type' => '—'],
                'temperature' => ['value' => 0, 'source' => '—'],
            ]]);
        }
    }

    /**
     * System info (hostname, OS, kernel, uptime, load average) from the server.
     */
    public function systemInfo(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        try {
            $data = app(SystemMetricsService::class)->getSystemInfo();
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::warning('ObserveController::systemInfo failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [
                'hostname' => gethostname() ?: '—',
                'os' => PHP_OS,
                'kernel' => php_uname('r') ?: '—',
                'uptime' => '—',
                'loadAverage' => '—',
            ]]);
        }
    }

    /**
     * Performance thresholds for dashboard display (defaults; per-service thresholds are in Monitored Targets).
     */
    public function performanceThresholds(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json([
            'success' => true,
            'data' => [
                ['metric' => 'CPU', 'warning' => '70%', 'critical' => '90%'],
                ['metric' => 'Memory', 'warning' => '80%', 'critical' => '95%'],
                ['metric' => 'Disk', 'warning' => '85%', 'critical' => '95%'],
                ['metric' => 'Network', 'warning' => '70%', 'critical' => '90%'],
            ],
        ]);
    }

    /**
     * Port scan results for all hosts in the workspace (for Infrastructure Map).
     */
    public function portScans(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $hosts = ObserveTargetHost::where('workspace_id', $project->id)
            ->where('enabled', true)
            ->with(['portScans' => fn ($q) => $q->orderByDesc('id')->limit(1)->with('results')])
            ->get();

        $data = $hosts->map(function ($host) {
            $latestScan = $host->portScans->first();
            $ports = $latestScan
                ? $latestScan->results->map(fn ($r) => [
                    'port' => $r->port,
                    'protocol' => $r->protocol,
                    'state' => $r->state,
                    'service' => $r->service,
                    'version' => $r->version,
                ])->values()->all()
                : [];

            return [
                'host_id' => $host->id,
                'host_name' => $host->name,
                'address' => $host->address,
                'scan' => $latestScan ? [
                    'id' => $latestScan->id,
                    'status' => $latestScan->status,
                    'scanned_at' => $latestScan->scanned_at?->toIso8601String(),
                    'open_ports_count' => $latestScan->open_ports_count,
                    'error_message' => $latestScan->error_message,
                ] : null,
                'ports' => $ports,
            ];
        })->values()->all();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Trigger nmap port scan(s) with custom options.
     * POST /workspaces/{project}/observe/infrastructure/port-scans/run
     * Body: { host_ids?: number[], ports?: 'top100'|'all'|'range', ports_range?: string, protocol?: 'tcp'|'udp' }
     */
    public function runPortScans(Request $request, Project $project): JsonResponse
    {
        $this->authorize('runObserveOperations', $project);

        $validated = $request->validate([
            'host_ids' => 'nullable|array',
            'host_ids.*' => 'integer',
            'ports' => 'nullable|string|in:top100,all,range',
            'ports_range' => 'nullable|string|max:500',
            'protocol' => 'nullable|string|in:tcp,udp',
        ]);

        $hostIds = $validated['host_ids'] ?? null;
        $options = [
            'ports' => $validated['ports'] ?? 'top100',
            'ports_range' => $validated['ports_range'] ?? '',
            'protocol' => $validated['protocol'] ?? 'tcp',
        ];

        $query = ObserveTargetHost::where('workspace_id', $project->id)->where('enabled', true);
        if ($hostIds !== null && count($hostIds) > 0) {
            $query->whereIn('id', $hostIds);
        }
        $hosts = $query->get();

        if ($hosts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hosts to scan. Add hosts in Monitored Targets first.',
            ], 400);
        }

        // Dispatch jobs to queue (database/redis). Worker must be running: php artisan queue:work
        // With sync driver, jobs run in-process; with database driver, jobs run in worker (recommended for long scans)
        foreach ($hosts as $host) {
            NmapPortScanJob::dispatch($host->id, $options);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scan started. Results will appear when complete—refresh the page or wait for auto-refresh.',
            'data' => [
                'scanned' => $hosts->count(),
                'total' => $hosts->count(),
                'errors' => [],
            ],
        ]);
    }

    /**
     * Network topology from Observe data: targets + service status. Real data only.
     * Returns nodes array for backward compatibility (e.g. frontend NetworkNode[]).
     */
    public function networkTopology(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $data = $this->buildTopologyFromObserve($project);
        return response()->json(['success' => true, 'data' => $data['nodes']]);
    }

    /**
     * Infrastructure connections: topology from Observe + optional merge from Integrations (external topology).
     * GET ?include_integrations=1 to merge integration-provided nodes/connections.
     */
    public function infrastructureConnections(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $includeIntegrations = $request->query('include_integrations', '1') === '1' || $request->query('include_integrations') === 'true';
        $data = $this->buildTopologyFromObserve($project);
        $integrationSources = [];
        if ($includeIntegrations) {
            $merged = $this->mergeIntegrationTopology($project, $data, $integrationSources);
            $data = $merged;
        }
        $response = [
            'nodes' => $data['nodes'],
            'connections' => $data['connections'],
            'service_stats' => $data['service_stats'] ?? [],
        ];
        if ($includeIntegrations && count($integrationSources) > 0) {
            $response['from_integrations'] = $integrationSources;
        }
        return response()->json(['success' => true, 'data' => $response]);
    }

    /**
     * Build topology (nodes + connections) from Observe targets and services only.
     *
     * @return array{nodes: array, connections: array, service_stats: array}
     */
    private function buildTopologyFromObserve(Project $project): array
    {
        $prefix = 'ws' . $project->id . '-';
        $hosts = ObserveTargetHost::where('workspace_id', $project->id)->where('enabled', true)->get();
        $serviceRows = ObserveService::where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $prefix . '%')
            ->get();
        $hostToState = [];
        $hostToServiceCount = ['total' => [], 'critical' => [], 'warning' => []];
        foreach ($serviceRows as $s) {
            $hostName = str_starts_with($s->host_name, $prefix) ? substr($s->host_name, strlen($prefix)) : $s->host_name;
            $hostToState[$hostName] = $this->worstState($hostToState[$hostName] ?? 'pending', $s->state);
            $hostToServiceCount['total'][$hostName] = ($hostToServiceCount['total'][$hostName] ?? 0) + 1;
            if (in_array($s->state, ['critical', 'unreachable'], true)) {
                $hostToServiceCount['critical'][$hostName] = ($hostToServiceCount['critical'][$hostName] ?? 0) + 1;
            } elseif (in_array($s->state, ['warning', 'unknown'], true)) {
                $hostToServiceCount['warning'][$hostName] = ($hostToServiceCount['warning'][$hostName] ?? 0) + 1;
            }
        }
        $nodes = [];
        $seenNet = [];
        foreach ($hosts as $h) {
            $name = $h->name;
            $address = $h->address ?? '';
            $status = $hostToState[$name] ?? 'pending';
            $nodes[] = [
                'id' => 'host-' . $name,
                'name' => $name,
                'type' => 'host',
                'address' => $address,
                'status' => $status,
                'layer' => 'Compute',
                'source' => 'observe',
            ];
            $parts = array_map('trim', explode('.', $address));
            $isIPv4 = count($parts) === 4 && array_reduce($parts, fn ($ok, $p) => $ok && ctype_digit($p), true);
            $netKey = $isIPv4 ? ($parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24') : ($address ?: 'default');
            if (!isset($seenNet[$netKey])) {
                $seenNet[$netKey] = true;
                $nodes[] = [
                    'id' => 'net-' . $netKey,
                    'name' => $netKey,
                    'type' => 'network',
                    'layer' => 'Network',
                    'source' => 'observe',
                ];
            }
        }
        $connections = [];
        foreach ($hosts as $h) {
            $connections[] = [
                'id' => 'mon-' . $h->name,
                'source' => $h->name,
                'destination' => 'Monitoring',
                'type' => 'monitored',
                'status' => $this->stateToLabel($hostToState[$h->name] ?? 'pending'),
            ];
        }
        $serviceStats = [];
        foreach (array_keys($hostToServiceCount['total']) as $hostName) {
            $serviceStats[$hostName] = [
                'total' => $hostToServiceCount['total'][$hostName] ?? 0,
                'critical' => $hostToServiceCount['critical'][$hostName] ?? 0,
                'warning' => $hostToServiceCount['warning'][$hostName] ?? 0,
            ];
        }
        return ['nodes' => $nodes, 'connections' => $connections, 'service_stats' => $serviceStats];
    }

    private function worstState(string $a, string $b): string
    {
        $order = ['critical' => 1, 'unreachable' => 2, 'warning' => 3, 'unknown' => 4, 'pending' => 5, 'ok' => 6];
        return ($order[$a] ?? 99) <= ($order[$b] ?? 99) ? $a : $b;
    }

    private function stateToLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'Online',
            'warning' => 'Warning',
            'critical', 'unreachable' => 'Critical',
            default => 'Pending',
        };
    }

    /**
     * Merge topology from project integrations (settings.topology_enabled + topology_data).
     *
     * @param array{nodes: array, connections: array} $observeData
     * @param array $integrationSources filled with integration names that contributed
     * @return array{nodes: array, connections: array}
     */
    private function mergeIntegrationTopology(Project $project, array $observeData, array &$integrationSources): array
    {
        $configs = IntegrationConfiguration::where('project_id', $project->id)->with('integration')->get();
        $nodes = $observeData['nodes'];
        $connections = $observeData['connections'];
        foreach ($configs as $config) {
            $settings = $config->settings ?? [];
            if (empty($settings['topology_enabled'])) {
                continue;
            }
            $topology = $settings['topology_data'] ?? null;
            if (is_string($topology)) {
                $decoded = json_decode($topology, true);
                $topology = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($topology)) {
                continue;
            }
            $integrationName = $config->integration?->name ?? 'External';
            $integrationSources[] = $integrationName;
            $extraNodes = $topology['nodes'] ?? [];
            $extraConnections = $topology['connections'] ?? [];
            foreach ($extraNodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $nodes[] = array_merge($n, ['source' => 'integration', 'integration' => $integrationName]);
            }
            foreach ($extraConnections as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $connections[] = array_merge($c, ['source_origin' => 'integration', 'integration' => $integrationName]);
            }
        }
        return ['nodes' => $nodes, 'connections' => $connections];
    }
}

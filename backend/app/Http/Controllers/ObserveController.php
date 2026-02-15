<?php

namespace App\Http\Controllers;

use App\Jobs\NmapPortScanJob;
use App\Models\HostPortScan;
use App\Models\ObserveService;
use App\Models\ObserveMeta;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\IntegrationConfiguration;
use App\Models\Project;
use App\Services\SystemMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ObserveController extends Controller
{
    /**
     * Get active service definitions for capability-driven UI.
     * GET /api/workspaces/{project}/observe/service-definitions?engine=nagios&status=active
     */
    public function serviceDefinitions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        if (!class_exists(ObserveServiceDefinition::class) || !\Illuminate\Support\Facades\Schema::hasTable('observe_service_definitions')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $engine = $request->query('engine', 'nagios');
        $status = $request->query('status', 'active');

        $definitions = ObserveServiceDefinition::query()
            ->where('engine', $engine)
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
            ->whereIn('engine_key', ['nagios', 'native'])
            ->orderByDesc('last_poll_at')
            ->first();

        $prefix = 'ws' . $project->id . '-';
        $allServices = ObserveService::where('workspace_id', $project->id)
            ->whereIn('engine_key', ['nagios', 'native'])
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

        // Build query with workspace scoping (include both nagios and native engine data)
        $workspacePrefix = 'ws' . $project->id . '-';
        $query = ObserveService::where('workspace_id', $project->id)
            ->whereIn('engine_key', ['nagios', 'native'])
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

        // Fetch all matching services (both engines); dedupe by (host_name, service_name), prefer native
        $allRows = $query->get();
        $keyFn = fn ($s) => $s->host_name . '::' . $s->service_name;
        $deduped = $allRows->groupBy($keyFn)->map(function ($group) {
            $native = $group->firstWhere('engine_key', 'native');
            return $native ?? $group->first();
        })->values();

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

        // Get meta for last_poll_at (prefer latest from nagios or native)
        $nativeMeta = ObserveMeta::where('workspace_id', $project->id)->where('engine_key', 'native')->first();
        $nagiosMeta = ObserveMeta::where('workspace_id', $project->id)->where('engine_key', 'nagios')->first();
        $meta = $nativeMeta ?? $nagiosMeta;
        if ($nativeMeta && $nagiosMeta && ($nagiosMeta->last_poll_at?->getTimestamp() ?? 0) > ($nativeMeta->last_poll_at?->getTimestamp() ?? 0)) {
            $meta = $nagiosMeta;
        }

        // Seed ObserveMeta when none exists (e.g. fresh workspace, scheduler not run yet) to avoid "Last poll: never" UX
        if ($meta === null || $meta->last_poll_at === null) {
            $meta = ObserveMeta::updateOrCreate(
                ['workspace_id' => $project->id, 'engine_key' => 'native'],
                ['last_poll_at' => now(), 'service_totals_json' => null, 'error' => null]
            );
        }

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

        $lastPollAt = $meta?->last_poll_at?->toIso8601String();
        // Only report unreachable from native engine (ShieldObserve). Nagios poll failures are ignored.
        $engineUnreachable = $nativeMeta && trim((string) ($nativeMeta->error ?? '')) !== '';
        $engineUnreachableReason = $engineUnreachable
            ? (trim((string) ($nativeMeta->error ?? '')) ?: 'Monitoring engine could not be reached.')
            : null;
        $staleThresholdSeconds = (int) config('observe.stale_threshold_seconds', 300);
        $sourceTimestamp = $lastPollAt;
        $stale = $lastPollAt
            ? (now()->parse($lastPollAt)->diffInSeconds(now(), false) > $staleThresholdSeconds)
            : true;

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
     * Stub: performance metrics (no backend implementation yet). Returns empty array.
     */
    public function performanceMetrics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: capacity metrics (no backend implementation yet). Returns empty array.
     */
    public function capacityMetrics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: alert rules (no backend implementation yet). Returns empty array.
     */
    public function alertRules(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: alert summary (no backend implementation yet). Returns empty summary.
     */
    public function alertSummary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json([
            'success' => true,
            'data' => [
                'total' => 0,
                'by_severity' => [],
                'by_status' => [],
            ],
        ]);
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
     * Stub: reports list (no backend implementation yet). Returns empty array.
     */
    public function reports(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: reports summary (no backend implementation yet). Returns empty summary.
     */
    public function reportSummary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json([
            'success' => true,
            'data' => [
                'total' => 0,
                'by_type' => [],
            ],
        ]);
    }

    /**
     * Stub: data sources list (no backend implementation yet). Returns empty array.
     */
    public function dataSources(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: data sources summary (no backend implementation yet). Returns empty summary.
     */
    public function dataSourceSummary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json([
            'success' => true,
            'data' => [
                'connected' => 0,
                'totalRecords' => '0',
                'syncStatus' => 0,
                'lastUpdate' => '',
            ],
        ]);
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
        $this->authorize('view', $project);

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

        // Dispatch jobs to run after response is sent (avoids timeout; user gets immediate feedback)
        foreach ($hosts as $host) {
            NmapPortScanJob::dispatch($host->id, $options)->afterResponse();
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
            ->whereIn('engine_key', ['nagios', 'native'])
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

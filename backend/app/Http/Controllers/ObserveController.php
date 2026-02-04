<?php

namespace App\Http\Controllers;

use App\Models\ObserveService;
use App\Models\ObserveMeta;
use App\Models\ObserveServiceDefinition;
use App\Models\Project;
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

        // Apply severity sort and limit
        $sorted = $deduped->sortBy(fn ($s) => match ($s->state) {
            'critical' => 1,
            'warning' => 2,
            'unknown' => 3,
            'unreachable' => 4,
            'pending' => 5,
            'ok' => 6,
            default => 7,
        });
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

        return response()->json([
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
        ]);
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
     * Stub: real-time metrics (no backend implementation yet). Returns empty object.
     */
    public function realTimeMetrics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => (object) []]);
    }

    /**
     * Stub: system info (no backend implementation yet). Returns empty object.
     */
    public function systemInfo(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => (object) []]);
    }

    /**
     * Stub: performance thresholds (no backend implementation yet). Returns empty array.
     */
    public function performanceThresholds(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Stub: network topology (no backend implementation yet). Returns empty array.
     */
    public function networkTopology(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        return response()->json(['success' => true, 'data' => []]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ObserveService;
use App\Models\ObserveMeta;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ObserveController extends Controller
{
    /**
     * Get observe summary for a workspace
     */
    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $meta = ObserveMeta::where('workspace_id', $project->id)
            ->where('engine_key', 'nagios')
            ->first();

        $totals = $meta?->service_totals_json ?? [
            'ok' => 0,
            'warning' => 0,
            'critical' => 0,
            'unknown' => 0,
            'pending' => 0,
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

        // Build query with workspace scoping (only include hosts with ws{workspaceId}- prefix)
        $workspacePrefix = 'ws' . $project->id . '-';
        $query = ObserveService::where('workspace_id', $project->id)
            ->where('engine_key', 'nagios')
            ->where('host_name', 'like', $workspacePrefix . '%');

        // Apply status filter
        if ($statusParam) {
            $statuses = is_array($statusParam) ? $statusParam : explode(',', $statusParam);
            $query->whereIn('state', array_map('trim', $statuses));
        }

        // Apply problems filter
        if ($problemsOnly) {
            $query->whereIn('state', ['warning', 'critical', 'unknown']);
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

        // Sort by severity: critical > warning > unknown > pending > ok
        $query->orderByRaw("
            CASE state
                WHEN 'critical' THEN 1
                WHEN 'warning' THEN 2
                WHEN 'unknown' THEN 3
                WHEN 'pending' THEN 4
                WHEN 'ok' THEN 5
                ELSE 6
            END
        ");

        // Apply limit
        $services = $query->limit($limit)->get();

        // Calculate totals from actual data (workspace-scoped)
        $allServices = ObserveService::where('workspace_id', $workspace->id)
            ->where('engine_key', 'nagios')
            ->where('host_name', 'like', $workspacePrefix . '%')
            ->get();

        $serviceTotals = [
            'ok' => $allServices->where('state', 'ok')->count(),
            'warning' => $allServices->where('state', 'warning')->count(),
            'critical' => $allServices->where('state', 'critical')->count(),
            'unknown' => $allServices->where('state', 'unknown')->count(),
            'pending' => $allServices->where('state', 'pending')->count(),
        ];

        // Calculate host totals
        $hosts = $allServices->groupBy('host_name');
        $hostTotals = [
            'up' => $hosts->count(), // Simplified: assume all hosts are up if they have services
            'down' => 0,
            'unreachable' => 0,
            'pending' => 0,
        ];

        // Get meta for last_poll_at
        $meta = ObserveMeta::where('workspace_id', $project->id)
            ->where('engine_key', 'nagios')
            ->first();

        // Transform services to match frontend format
        $items = $services->map(function ($service) {
            return [
                'host' => $service->host_name,
                'service' => $service->service_name,
                'status' => $service->state,
                'lastCheckAt' => $service->last_check_at?->toIso8601String() ?? '',
                'durationSec' => $service->duration_sec ?? 0,
                'attempt' => $service->attempt ?? '1/3',
                'info' => $service->output ?? '',
            ];
        })->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'hostTotals' => $hostTotals,
                'serviceTotals' => $serviceTotals,
                'items' => $items,
                'last_poll_at' => $meta?->last_poll_at?->toIso8601String(),
            ],
        ]);
    }
}

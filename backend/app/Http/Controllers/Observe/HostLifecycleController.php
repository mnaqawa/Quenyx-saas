<?php

namespace App\Http\Controllers\Observe;

use App\Http\Controllers\Controller;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Services\PlatformAgent\HostLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Constants\HostLifecycleStatus;

class HostLifecycleController extends Controller
{
    public function __construct(
        private HostLifecycleService $lifecycle
    ) {
    }

    public function disableMonitoring(Request $request, Project $project, ObserveTargetHost $host): JsonResponse
    {
        $this->authorize('update', $project);
        $this->assertHost($project, $host);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->lifecycle->disableMonitoring($project, $host, $request->user(), $validated['reason'] ?? null);

        return response()->json(['success' => true, 'data' => $this->serializeHost($updated)]);
    }

    public function suspend(Request $request, Project $project, ObserveTargetHost $host): JsonResponse
    {
        $this->authorize('update', $project);
        $this->assertHost($project, $host);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->lifecycle->suspend($project, $host, $request->user(), $validated['reason'] ?? null);

        return response()->json(['success' => true, 'data' => $this->serializeHost($updated)]);
    }

    public function archive(Request $request, Project $project, ObserveTargetHost $host): JsonResponse
    {
        $this->authorize('update', $project);
        $this->assertHost($project, $host);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->lifecycle->archive($project, $host, $request->user(), $validated['reason'] ?? null);

        return response()->json(['success' => true, 'data' => $this->serializeHost($updated)]);
    }

    public function restore(Request $request, Project $project, ObserveTargetHost $host): JsonResponse
    {
        $this->authorize('update', $project);
        $this->assertHost($project, $host);

        $updated = $this->lifecycle->restore($project, $host, $request->user());

        return response()->json(['success' => true, 'data' => $this->serializeHost($updated)]);
    }

    public function destroy(Request $request, Project $project, ObserveTargetHost $host): JsonResponse
    {
        $this->authorize('update', $project);
        $this->assertHost($project, $host);

        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->lifecycle->delete($project, $host, $request->user(), (bool) ($validated['force'] ?? false));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    private function assertHost(Project $project, ObserveTargetHost $host): void
    {
        if ((int) $host->workspace_id !== (int) $project->id) {
            abort(404, 'Host not found');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHost(ObserveTargetHost $host): array
    {
        return [
            'id' => $host->id,
            'uuid' => $host->uuid,
            'name' => $host->name,
            'lifecycle_status' => $host->lifecycle_status ?? HostLifecycleStatus::ACTIVE,
            'lifecycle_reason' => $host->lifecycle_reason,
            'lifecycle_changed_at' => $host->lifecycle_changed_at?->toIso8601String(),
            'enabled' => $host->enabled,
            'agent_id' => $host->agent_id,
        ];
    }
}

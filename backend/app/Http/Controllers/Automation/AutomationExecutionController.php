<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Models\Automation\AutomationExecution;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\AutomationAdapterRegistry;
use App\Services\Automation\AutomationLearningService;
use App\Services\Automation\ExecutionEngine;
use App\Services\Automation\ExecutionHistory;
use App\Services\Automation\RollbackEngine;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sprint 23 — Executions API: ad-hoc action dispatch (registry-driven), history, rollback, and
 * operator feedback. Dry-run is the default; live execution requires `administerAi` and is gated by
 * the approval engine. All side effects are audited and recorded for Automation Learning.
 */
class AutomationExecutionController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly ExecutionEngine $engine,
        private readonly ExecutionHistory $history,
        private readonly RollbackEngine $rollback,
        private readonly AutomationAdapterRegistry $adapters,
        private readonly AutomationLearningService $learning,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['executions' => $this->history->list($project, [
            'status' => $request->query('status'),
            'adapter_key' => $request->query('adapter_key'),
            'limit' => (int) $request->query('limit', 100),
        ])]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $execution = $this->resolve($project->id, $uuid);

        return $this->ok($this->history->detail($execution));
    }

    /** POST /executions — dispatch a single registry-driven action. */
    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'adapter_key' => 'required|string|max:64',
            'action_key' => 'nullable|string|max:96',
            'parameters' => 'nullable|array',
            'target' => 'nullable|array',
            'mode' => 'nullable|in:dry_run,live',
            'timeout_seconds' => 'nullable|integer|min:1|max:3600',
            'max_retries' => 'nullable|integer|min:0|max:5',
            'incident_id' => 'nullable|integer',
        ]);

        if (! $this->adapters->has($data['adapter_key'])) {
            return $this->fail('Unknown execution adapter: '.$data['adapter_key'], 'unknown_adapter', 422);
        }

        if (($data['mode'] ?? 'dry_run') === 'live') {
            $this->requireAdmin($project);
        }

        $execution = $this->engine->dispatch($project, $request->user(), $data);

        return $this->ok($this->history->detail($execution), 201);
    }

    /** POST /executions/{uuid}/rollback */
    public function rollback(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $execution = $this->resolve($project->id, $uuid);

        try {
            $execution = $this->rollback->rollback($execution, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 'rollback_unavailable', 422);
        }

        return $this->ok($this->history->detail($execution));
    }

    /** POST /executions/{uuid}/feedback */
    public function feedback(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $execution = $this->resolve($project->id, $uuid);
        $data = $request->validate(['feedback' => 'required|string|max:500']);

        $this->learning->feedback($execution, $data['feedback']);

        return $this->ok(['recorded' => true]);
    }

    private function resolve(int $projectId, string $uuid): AutomationExecution
    {
        return AutomationExecution::where('project_id', $projectId)->where('uuid', $uuid)->with(['steps', 'approval'])->firstOrFail();
    }
}

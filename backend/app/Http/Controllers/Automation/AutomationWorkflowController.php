<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationWorkflow;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\ExecutionHistory;
use App\Services\Automation\WorkflowEngine;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Sprint 23 — Workflow CRUD + run. A workflow's actions run through the shared Execution Engine, so
 * all safety/approval/audit rules apply. Live runs require `administerAi` and flow through approval.
 */
class AutomationWorkflowController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly WorkflowEngine $engine,
        private readonly ExecutionHistory $history,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        $workflows = AutomationWorkflow::where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AutomationWorkflow $w): array => $this->summary($w))
            ->all();

        return $this->ok(['workflows' => $workflows]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'name' => 'required|string|max:180',
            'description' => 'nullable|string|max:500',
            'trigger_type' => 'required|in:manual,scheduled,event,api',
            'schedule' => 'nullable|string|max:120',
            'requires_approval' => 'boolean',
            'definition' => 'required|array',
        ]);

        try {
            $definition = $this->engine->normalizeDefinition($data['definition']);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 'invalid_definition', 422);
        }

        $workflow = AutomationWorkflow::create([
            'project_id' => $project->id,
            'created_by' => $request->user()?->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'trigger_type' => $data['trigger_type'],
            'schedule' => $data['schedule'] ?? null,
            'requires_approval' => (bool) ($data['requires_approval'] ?? true),
            'enabled' => true,
            'definition' => $definition,
        ]);

        return $this->ok($this->detail($workflow), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $workflow = $this->resolve($project->id, $uuid);

        return $this->ok($this->detail($workflow));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $workflow = $this->resolve($project->id, $uuid);
        $data = $request->validate([
            'name' => 'sometimes|string|max:180',
            'description' => 'nullable|string|max:500',
            'trigger_type' => 'sometimes|in:manual,scheduled,event,api',
            'schedule' => 'nullable|string|max:120',
            'requires_approval' => 'boolean',
            'enabled' => 'boolean',
            'definition' => 'sometimes|array',
        ]);

        if (isset($data['definition'])) {
            try {
                $data['definition'] = $this->engine->normalizeDefinition($data['definition']);
            } catch (InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 'invalid_definition', 422);
            }
        }

        $workflow->update($data);

        return $this->ok($this->detail($workflow->fresh()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $this->resolve($project->id, $uuid)->delete();

        return $this->ok(['deleted' => true]);
    }

    /** POST /workflows/{uuid}/run */
    public function run(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $workflow = $this->resolve($project->id, $uuid);
        $mode = $request->input('mode') === 'live' ? 'live' : 'dry_run';

        if ($mode === 'live') {
            $this->requireAdmin($project);
        }

        $executions = $this->engine->run($workflow, $request->user(), [
            'mode' => $mode,
            'incident_id' => $request->input('incident_id'),
        ]);

        return $this->ok([
            'mode' => $mode,
            'executions' => array_map(fn (AutomationExecution $e): array => $this->history->summary($e), $executions),
        ]);
    }

    private function resolve(int $projectId, string $uuid): AutomationWorkflow
    {
        return AutomationWorkflow::where('project_id', $projectId)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(AutomationWorkflow $w): array
    {
        return [
            'uuid' => $w->uuid,
            'name' => $w->name,
            'description' => $w->description,
            'trigger_type' => $w->trigger_type,
            'schedule' => $w->schedule,
            'enabled' => (bool) $w->enabled,
            'requires_approval' => (bool) $w->requires_approval,
            'action_count' => count($w->definition['actions'] ?? []),
            'created_at' => optional($w->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(AutomationWorkflow $w): array
    {
        return array_merge($this->summary($w), ['definition' => $w->definition]);
    }
}

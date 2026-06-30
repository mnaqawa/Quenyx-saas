<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationRunbook;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\ExecutionHistory;
use App\Services\Automation\RunbookEngine;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Sprint 23 — Runbook CRUD + AI-assisted drafting + run. AI suggestions are editable drafts and are
 * NEVER auto-executed; running a runbook flows through the shared Execution Engine + approval gate.
 */
class AutomationRunbookController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly RunbookEngine $engine,
        private readonly ExecutionHistory $history,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        $runbooks = AutomationRunbook::where('project_id', $project->id)
            ->orderByDesc('created_at')->get()
            ->map(fn (AutomationRunbook $r): array => $this->summary($r))->all();

        return $this->ok(['runbooks' => $runbooks]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'name' => 'required|string|max:180',
            'category' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:500',
            'source' => 'nullable|in:manual,ai_assisted',
            'status' => 'nullable|in:draft,published',
            'definition' => 'required|array',
        ]);

        try {
            $definition = $this->engine->normalizeDefinition($data['definition']);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 'invalid_definition', 422);
        }

        $runbook = AutomationRunbook::create([
            'project_id' => $project->id,
            'created_by' => $request->user()?->id,
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'status' => $data['status'] ?? 'draft',
            'definition' => $definition,
        ]);

        return $this->ok($this->detail($runbook), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->detail($this->resolve($project->id, $uuid)));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $runbook = $this->resolve($project->id, $uuid);
        $data = $request->validate([
            'name' => 'sometimes|string|max:180',
            'category' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:500',
            'status' => 'sometimes|in:draft,published',
            'definition' => 'sometimes|array',
        ]);

        if (isset($data['definition'])) {
            try {
                $data['definition'] = $this->engine->normalizeDefinition($data['definition']);
            } catch (InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 'invalid_definition', 422);
            }
        }

        $runbook->update($data);

        return $this->ok($this->detail($runbook->fresh()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $this->resolve($project->id, $uuid)->delete();

        return $this->ok(['deleted' => true]);
    }

    /** POST /runbooks/{uuid}/run */
    public function run(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $runbook = $this->resolve($project->id, $uuid);
        $mode = $request->input('mode') === 'live' ? 'live' : 'dry_run';

        if ($mode === 'live') {
            $this->requireAdmin($project);
        }

        $executions = $this->engine->run($runbook, $request->user(), [
            'mode' => $mode,
            'incident_id' => $request->input('incident_id'),
        ]);

        return $this->ok([
            'mode' => $mode,
            'executions' => array_map(fn (AutomationExecution $e): array => $this->history->summary($e), $executions),
        ]);
    }

    private function resolve(int $projectId, string $uuid): AutomationRunbook
    {
        return AutomationRunbook::where('project_id', $projectId)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(AutomationRunbook $r): array
    {
        return [
            'uuid' => $r->uuid,
            'name' => $r->name,
            'category' => $r->category,
            'description' => $r->description,
            'source' => $r->source,
            'status' => $r->status,
            'step_count' => count($r->definition['steps'] ?? []),
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(AutomationRunbook $r): array
    {
        return array_merge($this->summary($r), ['definition' => $r->definition]);
    }
}

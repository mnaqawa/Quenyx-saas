<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Models\Automation\AutomationExecution;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\Intelligence\QynRunIntelligenceService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — QynRun Automation Intelligence API (the AI surface). Overview is read-only; copilot,
 * runbook drafting, and execution explanation require the `can_use_ai` capability. All answers are
 * grounded in real automation evidence and narrated through the shared AI runtime.
 */
class AutomationIntelligenceController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly QynRunIntelligenceService $intelligence,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function overview(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->intelligence->overview($project));
    }

    public function copilot(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->intelligence->copilot($project, $request->user(), (string) $data['message'], $data['conversation'] ?? null));
    }

    /** POST /runbooks/suggest — editable AI-assisted draft (not persisted, not executed). */
    public function suggestRunbook(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate(['problem' => 'required|string|max:500']);

        return $this->ok($this->intelligence->suggestRunbook($project, $request->user(), $data['problem']));
    }

    /** POST /executions/{uuid}/explain */
    public function explainExecution(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $execution = AutomationExecution::where('project_id', $project->id)->where('uuid', $uuid)->with('steps')->firstOrFail();

        return $this->ok($this->intelligence->explainExecution($project, $request->user(), $execution));
    }
}

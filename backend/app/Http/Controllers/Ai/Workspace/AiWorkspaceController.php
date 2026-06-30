<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\AI\Workspace\AiWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 — Unified AI Workspace read surface: summary, usage, costs, activity, notifications.
 * All figures are derived from real platform data (token counts + audit events) — never fabricated.
 */
class AiWorkspaceController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly AiWorkspaceService $service,
    ) {
        parent::__construct($context);
    }

    public function summary(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok([
            'summary' => $this->service->summary($project),
            'permissions' => $this->context->effectivePermissions($project, $request->user()),
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_view_costs');

        return $this->ok($this->service->usage($project));
    }

    public function costs(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_view_costs');

        return $this->ok($this->service->costs($project));
    }

    public function activity(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $limit = (int) config('ai.workspace.max_activity', 50);

        return $this->ok(['items' => $this->service->activity($project, $limit)]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $limit = (int) config('ai.workspace.max_activity', 50);

        return $this->ok(['items' => $this->service->notifications($project, $limit)]);
    }
}

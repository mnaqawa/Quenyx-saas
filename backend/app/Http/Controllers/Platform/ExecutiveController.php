<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Platform\Executive\ExecutiveIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — Executive Intelligence API. The dashboard is evidence-based and read-only; the executive
 * AI summary requires `can_use_ai` and narrates only the deterministic dashboard evidence.
 */
class ExecutiveController extends QynVaBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly ExecutiveIntelligenceService $executive,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->executive->dashboard($project));
    }

    public function summary(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $request->validate(['workspace' => 'required|string']);

        return $this->ok($this->executive->summary($project, $request->user()));
    }
}

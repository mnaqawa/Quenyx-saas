<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Platform\Cost\CostIntelligenceCopilot;
use App\Services\Platform\Cost\CostIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — QynBalance Cost Intelligence API. The overview is read-only and evidence-based; the copilot
 * requires `can_use_ai`. No fabricated financial values — pricing is reported as unavailable when unset.
 */
class CostController extends QynBalanceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly CostIntelligenceService $cost,
        private readonly CostIntelligenceCopilot $copilot,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function overview(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->cost->overview($project));
    }

    public function copilotAction(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->copilot->copilot($project, $request->user(), (string) $data['message'], $data['conversation'] ?? null));
    }
}

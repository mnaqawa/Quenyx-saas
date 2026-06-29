<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observe\Intelligence;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Observe\Intelligence\OperationsIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 21 — Operations Intelligence dashboard + Monitoring Copilot.
 */
class OperationsIntelligenceController extends OperationsIntelligenceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly OperationsIntelligenceService $service,
    ) {
        parent::__construct($context, $entitlements);
    }

    /** GET /api/qynsight/intelligence/overview — real operational intelligence (no AI call). */
    public function overview(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->service->overview($project));
    }

    /** POST /api/qynsight/intelligence/copilot — grounded operational Q&A (reuses Quenyx AI conversations). */
    public function copilot(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'message' => ['required', 'string', 'max:4000'],
            'conversation' => ['sometimes', 'nullable', 'string', 'uuid'],
        ]);

        return $this->ok($this->service->copilot(
            $project,
            $request->user(),
            (string) $validated['message'],
            $validated['conversation'] ?? null,
        ));
    }

    /** GET /api/qynsight/intelligence/recommendations — evidence-based recommendations (no AI call). */
    public function recommendations(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['recommendations' => $this->service->recommendations($project)]);
    }
}

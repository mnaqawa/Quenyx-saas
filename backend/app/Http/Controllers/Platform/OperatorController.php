<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Platform\Operator\QynVaOperatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — QynVA Enterprise AI Operator API. Capability discovery is read-only; operating (reasoning +
 * cross-module coordination plan) requires `can_use_ai`. QynVA never executes — it proposes editable plans.
 */
class OperatorController extends QynVaBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly QynVaOperatorService $operator,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->operator->capabilities($project));
    }

    public function operate(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->operator->operate($project, $request->user(), (string) $data['message'], $data['conversation'] ?? null));
    }
}

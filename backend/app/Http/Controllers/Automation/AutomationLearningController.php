<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\AutomationLearningService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — Automation Learning API. Returns the aggregated, auditable outcome statistics the AI
 * cites in recommendations. No model training, no hidden state — just inspectable history.
 */
class AutomationLearningController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly AutomationLearningService $learning,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function stats(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->learning->stats($project));
    }
}

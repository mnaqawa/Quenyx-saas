<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\AutomationAdapterRegistry;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — Automation Registry discovery API. Exposes the registered execution adapters and the
 * reusable action catalog so the UI is registry-driven (no hardcoded execution surfaces).
 */
class AutomationRegistryController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly AutomationAdapterRegistry $adapters,
        private readonly ActionRegistry $actions,
    ) {
        parent::__construct($context, $entitlements);
    }

    /** GET /api/qynrun/automation/adapters */
    public function adapters(Request $request): JsonResponse
    {
        $this->workspace($request);

        return $this->ok([
            'adapters' => $this->adapters->describeAll(),
            'live_execution_enabled' => (bool) config('automation.live_execution', false),
        ]);
    }

    /** GET /api/qynrun/automation/actions */
    public function actions(Request $request): JsonResponse
    {
        $this->workspace($request);

        return $this->ok(['actions' => $this->actions->all()]);
    }
}

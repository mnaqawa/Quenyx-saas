<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Platform\Analytics\EnterpriseAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — Enterprise Analytics API. Read-only, evidence-based metrics over real rows.
 */
class AnalyticsController extends QynVaBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly EnterpriseAnalyticsService $analytics,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $days = (int) $request->query('days', '30');

        return $this->ok($this->analytics->build($project, $days));
    }
}

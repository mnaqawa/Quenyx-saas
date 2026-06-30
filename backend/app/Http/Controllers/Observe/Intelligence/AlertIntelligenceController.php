<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observe\Intelligence;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Observe\Intelligence\AlertExplanationService;
use App\Services\Observe\Intelligence\IncidentTimelineService;
use App\Services\Observe\Intelligence\OperationsEntityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 21 — Alert Intelligence (Explain / Investigate) + Incident Timeline.
 */
class AlertIntelligenceController extends OperationsIntelligenceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly AlertExplanationService $alerts,
        private readonly IncidentTimelineService $timeline,
        private readonly OperationsEntityResolver $resolver,
    ) {
        parent::__construct($context, $entitlements);
    }

    /** POST /api/qynsight/intelligence/alerts/{uuid}/explain */
    public function explain(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $event = $this->resolver->resolveAlert($project, $uuid);
        abort_if($event === null, 404, 'Alert not found.');

        return $this->ok($this->alerts->explain($project, $request->user(), $event));
    }

    /** POST /api/qynsight/intelligence/alerts/{uuid}/investigate */
    public function investigate(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $event = $this->resolver->resolveAlert($project, $uuid);
        abort_if($event === null, 404, 'Alert not found.');

        return $this->ok($this->alerts->investigate($project, $request->user(), $event));
    }

    /** GET /api/qynsight/intelligence/incidents/{uuid}/timeline — deterministic, no AI call. */
    public function timeline(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);

        $event = $this->resolver->resolveAlert($project, $uuid);
        abort_if($event === null, 404, 'Incident not found.');

        return $this->ok($this->timeline->build($project, $event));
    }
}

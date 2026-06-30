<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observe\Intelligence;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Observe\Intelligence\CapacityAdvisorService;
use App\Services\Observe\Intelligence\InfrastructureImpactService;
use App\Services\Observe\Intelligence\OperationsAiAnalyst;
use App\Services\Observe\Intelligence\OperationsEntityResolver;
use App\Services\Observe\Intelligence\OperationsIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 21 — host / service / capacity / infrastructure intelligence (contextual AI actions).
 */
class ResourceIntelligenceController extends OperationsIntelligenceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly OperationsIntelligenceService $intelligence,
        private readonly CapacityAdvisorService $capacity,
        private readonly InfrastructureImpactService $infrastructure,
        private readonly OperationsAiAnalyst $analyst,
        private readonly OperationsEntityResolver $resolver,
    ) {
        parent::__construct($context, $entitlements);
    }

    /** POST /api/qynsight/intelligence/hosts/{uuid}/explain — Service Health Intelligence. */
    public function explainHost(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveHost($project, $uuid);
        abort_if($host === null, 404, 'Host not found.');

        return $this->ok($this->intelligence->explainHost($project, $request->user(), $host));
    }

    /** POST /api/qynsight/intelligence/services/{uuid}/analyze. */
    public function analyzeService(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $service = $this->resolver->resolveService($project, $uuid);
        abort_if($service === null, 404, 'Service not found.');

        return $this->ok($this->intelligence->analyzeService($project, $request->user(), $service));
    }

    /** POST /api/qynsight/intelligence/capacity/{uuid}/predict — {uuid} is a host UUID. */
    public function predictCapacity(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveHost($project, $uuid);
        abort_if($host === null, 404, 'Host not found.');

        $prediction = $this->capacity->predict($project, $host);

        $ai = $this->analyst->narrate(
            $project,
            $request->user(),
            'ops_capacity_prediction',
            $prediction,
            sprintf('Explain the capacity outlook for host %s: growth trend, forecast, estimated exhaustion, operational impact, recommended action, and business risk — using only the evidence.', $host->name),
            'capacity_predict',
            'qynsight.intelligence.capacity.predict',
            'text',
        );

        return $this->ok(array_merge($prediction, ['ai_explanation' => $ai]));
    }

    /** POST /api/qynsight/intelligence/infrastructure/{uuid}/impact — {uuid} is a host UUID. */
    public function impact(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveHost($project, $uuid);
        abort_if($host === null, 404, 'Host not found.');

        $impact = $this->infrastructure->impact($project, $host);

        $ai = $this->analyst->narrate(
            $project,
            $request->user(),
            'ops_infrastructure_impact',
            $impact,
            sprintf('Explain the impact if host %s fails: which services and dependencies are affected, the critical path, single point of failure risk, and potential cascading failures — using only the topology evidence.', $host->name),
            'infrastructure_impact',
            'qynsight.intelligence.infrastructure.impact',
            'text',
        );

        return $this->ok(array_merge($impact, ['ai_explanation' => $ai]));
    }
}

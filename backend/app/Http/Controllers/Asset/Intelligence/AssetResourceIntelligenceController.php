<?php

declare(strict_types=1);

namespace App\Http\Controllers\Asset\Intelligence;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\Asset\Intelligence\AssetEntityResolver;
use App\Services\Asset\Intelligence\AssetIntelligenceService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 22 — per-asset contextual AI actions (explain, dependency, lifecycle, impact) + license review.
 */
class AssetResourceIntelligenceController extends AssetIntelligenceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly AssetIntelligenceService $intelligence,
        private readonly AssetEntityResolver $resolver,
    ) {
        parent::__construct($context, $entitlements);
    }

    /** POST /api/qynasset/intelligence/assets/{uuid}/explain — Asset Discovery / CMDB explanation. */
    public function explain(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveAsset($project, $uuid);
        abort_if($host === null, 404, 'Asset not found.');

        return $this->ok($this->intelligence->explainAsset($project, $request->user(), $host));
    }

    /** POST /api/qynasset/intelligence/assets/{uuid}/dependencies — Dependency Intelligence. */
    public function dependencies(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveAsset($project, $uuid);
        abort_if($host === null, 404, 'Asset not found.');

        return $this->ok($this->intelligence->analyzeDependency($project, $request->user(), $host));
    }

    /** POST /api/qynasset/intelligence/assets/{uuid}/lifecycle — Lifecycle Intelligence. */
    public function lifecycle(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveAsset($project, $uuid);
        abort_if($host === null, 404, 'Asset not found.');

        return $this->ok($this->intelligence->forecastLifecycle($project, $request->user(), $host));
    }

    /** POST /api/qynasset/intelligence/assets/{uuid}/impact — Asset Relationship / impact analysis. */
    public function impact(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        $host = $this->resolver->resolveAsset($project, $uuid);
        abort_if($host === null, 404, 'Asset not found.');

        return $this->ok($this->intelligence->relationshipImpact($project, $request->user(), $host));
    }

    /** POST /api/qynasset/intelligence/licenses/review — License Intelligence (workspace-level). */
    public function reviewLicense(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        return $this->ok($this->intelligence->reviewLicense($project, $request->user()));
    }
}

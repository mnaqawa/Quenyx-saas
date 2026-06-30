<?php

declare(strict_types=1);

namespace App\Http\Controllers\Knowledge;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Knowledge\GlobalTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Global Timeline API. Platform-wide chronological view aggregated from real module rows.
 */
class GlobalTimelineController extends KnowledgeBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly GlobalTimelineService $timeline,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $types = $request->query('types');

        return $this->ok($this->timeline->build($project, [
            'limit' => $request->query('limit'),
            'types' => is_string($types) ? array_filter(explode(',', $types)) : [],
        ]));
    }
}

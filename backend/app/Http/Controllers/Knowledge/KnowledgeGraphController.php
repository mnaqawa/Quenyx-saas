<?php

declare(strict_types=1);

namespace App\Http\Controllers\Knowledge;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Knowledge\KnowledgeGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Enterprise Knowledge Graph v2 API. Typed nodes + traversable edges over real entities.
 */
class KnowledgeGraphController extends KnowledgeBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly KnowledgeGraphService $graph,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->graph->build($project));
    }
}

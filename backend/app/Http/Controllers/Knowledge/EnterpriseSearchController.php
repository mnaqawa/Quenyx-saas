<?php

declare(strict_types=1);

namespace App\Http\Controllers\Knowledge;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Knowledge\EnterpriseSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Enterprise Search API. One search interface across every module, registry-driven for
 * knowledge sources, over real indexed data only.
 */
class EnterpriseSearchController extends KnowledgeBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly EnterpriseSearchService $search,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function search(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'q' => 'required|string|max:500',
            'mode' => 'nullable|in:keyword,semantic',
            'limit' => 'nullable|integer|min:1|max:100',
            'types' => 'nullable|array',
            'types.*' => 'string|max:32',
        ]);

        return $this->ok($this->search->search($project, (string) $data['q'], [
            'mode' => $data['mode'] ?? 'keyword',
            'limit' => $data['limit'] ?? null,
            'types' => $data['types'] ?? [],
        ]));
    }
}

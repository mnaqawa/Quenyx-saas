<?php

declare(strict_types=1);

namespace App\Http\Controllers\Knowledge;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Knowledge\Intelligence\QynKnowIntelligenceService;
use App\Services\Knowledge\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Knowledge Assistant API (the AI surface). Overview is read-only; copilot, explain,
 * summarize, find-related, and drafting require the `can_use_ai` capability. Drafts are editable and
 * never auto-published.
 */
class KnowledgeIntelligenceController extends KnowledgeBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly QynKnowIntelligenceService $intelligence,
        private readonly KnowledgeService $knowledge,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function overview(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok($this->intelligence->overview($project));
    }

    public function copilot(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->intelligence->copilot($project, $request->user(), (string) $data['message'], $data['conversation'] ?? null));
    }

    public function explain(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $doc = $this->knowledge->find($project, $uuid);
        abort_if($doc === null, 404, 'Document not found.');

        return $this->ok($this->intelligence->explain($project, $request->user(), $doc));
    }

    public function summarize(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $doc = $this->knowledge->find($project, $uuid);
        abort_if($doc === null, 404, 'Document not found.');

        return $this->ok($this->intelligence->summarize($project, $request->user(), $doc));
    }

    public function related(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate(['workspace' => 'required|string', 'q' => 'required|string|max:500']);

        return $this->ok($this->intelligence->findRelated($project, $request->user(), (string) $data['q']));
    }

    public function draft(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'kind' => 'required|in:kb,incident_summary,executive_summary,technical_summary,runbook',
            'topic' => 'required|string|max:500',
        ]);

        return $this->ok($this->intelligence->draft($project, $request->user(), (string) $data['kind'], (string) $data['topic']));
    }
}

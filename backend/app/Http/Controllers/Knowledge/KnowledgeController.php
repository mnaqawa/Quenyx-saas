<?php

declare(strict_types=1);

namespace App\Http\Controllers\Knowledge;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Knowledge\KnowledgeService;
use App\Services\Knowledge\KnowledgeSourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Knowledge documents CRUD + Knowledge Source Registry discovery.
 */
class KnowledgeController extends KnowledgeBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly KnowledgeService $knowledge,
        private readonly KnowledgeSourceRegistry $sources,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function sources(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['sources' => $this->sources->describe($project)]);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['documents' => $this->knowledge->list($project, [
            'category' => $request->query('category'),
            'status' => $request->query('status'),
        ])]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $doc = $this->knowledge->find($project, $uuid);
        abort_if($doc === null, 404, 'Document not found.');

        return $this->ok($this->knowledge->detail($doc));
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $data = $request->validate([
            'workspace' => 'required|string',
            'title' => 'required|string|max:250',
            'body' => 'nullable|string',
            'category' => 'nullable|string|max:96',
            'status' => 'nullable|in:draft,published,archived',
            'format' => 'nullable|in:markdown,html,pdf,text',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
        ]);

        $doc = $this->knowledge->create($project, $request->user(), $data);

        return $this->ok($this->knowledge->detail($doc), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $doc = $this->knowledge->find($project, $uuid);
        abort_if($doc === null, 404, 'Document not found.');

        $data = $request->validate([
            'workspace' => 'required|string',
            'title' => 'sometimes|string|max:250',
            'body' => 'nullable|string',
            'category' => 'nullable|string|max:96',
            'status' => 'nullable|in:draft,published,archived',
            'format' => 'nullable|in:markdown,html,pdf,text',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
        ]);

        return $this->ok($this->knowledge->detail($this->knowledge->update($project, $request->user(), $doc, $data)));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $doc = $this->knowledge->find($project, $uuid);
        abort_if($doc === null, 404, 'Document not found.');

        $this->knowledge->delete($project, $request->user(), $doc);

        return $this->ok(['deleted' => true]);
    }
}

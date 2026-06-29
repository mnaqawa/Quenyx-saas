<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Http\Resources\Ai\AiPromptTemplateResource;
use App\Services\Ai\Workspace\AiPromptTemplateService;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 — workspace prompt template CRUD. Reads need access; writes need the can_manage_templates
 * capability. All addressing is UUID-only and every mutation is audited.
 */
class AiPromptTemplateController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly AiPromptTemplateService $service,
    ) {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(AiPromptTemplateResource::collection($this->service->list($project))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_manage_templates');

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:20000'],
            'variables' => ['sometimes', 'nullable', 'array'],
            'variables.*' => ['string', 'max:64'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $template = $this->service->create($project, $request->user(), $validated);

        return $this->ok((new AiPromptTemplateResource($template))->resolve(), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_manage_templates');

        $template = $this->service->findForProject($project, $uuid);
        abort_if($template === null, 404, 'Prompt template not found.');

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'body' => ['sometimes', 'string', 'max:20000'],
            'variables' => ['sometimes', 'nullable', 'array'],
            'variables.*' => ['string', 'max:64'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $template = $this->service->update($project, $request->user(), $template, $validated);

        return $this->ok((new AiPromptTemplateResource($template))->resolve());
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_manage_templates');

        $template = $this->service->findForProject($project, $uuid);
        abort_if($template === null, 404, 'Prompt template not found.');

        $this->service->delete($project, $request->user(), $template);

        return $this->ok(['deleted' => true]);
    }
}

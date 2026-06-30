<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Models\Ai\AiWorkspacePermission;
use App\Services\AI\Workspace\AiPermissionService;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint 20 — workspace AI permission matrix. Reading needs administer rights (it reveals the full
 * matrix); updating needs owner/admin (administerAi). Changes are audited.
 */
class AiPermissionController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly AiPermissionService $service,
    ) {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request, 'administerAi');

        return $this->ok([
            'roles' => $this->service->matrix($project),
            'caller' => $this->context->effectivePermissions($project, $request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $project = $this->workspace($request, 'administerAi');

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*.role' => ['required', Rule::in(AiWorkspacePermission::ROLES)],
            'permissions.*.can_use_ai' => ['sometimes', 'boolean'],
            'permissions.*.can_manage_providers' => ['sometimes', 'boolean'],
            'permissions.*.can_manage_templates' => ['sometimes', 'boolean'],
            'permissions.*.can_view_costs' => ['sometimes', 'boolean'],
            'permissions.*.can_administer' => ['sometimes', 'boolean'],
        ]);

        $matrix = $this->service->update($project, $request->user(), $validated['permissions']);

        return $this->ok(['roles' => $matrix]);
    }
}

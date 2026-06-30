<?php

declare(strict_types=1);

namespace App\Http\Controllers\Incident;

use App\Http\Controllers\Controller;
use App\Models\Incident\Incident;
use App\Models\Project;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — shared security envelope for the QynReact Incident Workspace controllers. Workspace
 * resolved from a REQUIRED `workspace` UUID, QynReact entitlement enforced, RBAC via ProjectPolicy
 * (`accessAi`), and the `{ success, data }` envelope. UUID-only incident addressing.
 */
abstract class IncidentBaseController extends Controller
{
    public function __construct(
        protected readonly AiWorkspaceContextResolver $context,
        protected readonly EntitlementService $entitlements,
    ) {}

    protected function workspace(Request $request): Project
    {
        $project = $this->context->resolve($request);

        abort_unless(
            $this->entitlements->hasEffectiveModuleAccess($project, 'qynreact'),
            403,
            'QynReact is not enabled for this workspace.'
        );

        $this->authorize('accessAi', $project);

        return $project;
    }

    protected function requireAi(Project $project, Request $request): void
    {
        $permissions = $this->context->effectivePermissions($project, $request->user());
        abort_unless((bool) ($permissions['can_use_ai'] ?? false), 403, 'You do not have permission to use Quenyx AI in this workspace.');
    }

    protected function resolveIncident(Project $project, string $uuid): Incident
    {
        return Incident::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }

    protected function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    protected function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — shared security envelope for QynVA (Enterprise AI Operator) + Enterprise Intelligence
 * surfaces (Executive Intelligence, Enterprise Analytics, Platform Health, Event Bus).
 *
 *  - Workspace resolved from a REQUIRED `workspace` UUID (never a numeric id).
 *  - QynVA module entitlement check.
 *  - RBAC via ProjectPolicy: `accessAi` for reads; `administerAi` for platform-operations surfaces;
 *    `can_use_ai` for AI surfaces.
 *  - `{ success, data }` envelope. Rate limiting + Sanctum auth applied at the route level.
 */
abstract class QynVaBaseController extends Controller
{
    public function __construct(
        protected readonly AiWorkspaceContextResolver $context,
        protected readonly EntitlementService $entitlements,
    ) {}

    protected function workspace(Request $request): Project
    {
        $project = $this->context->resolve($request);

        abort_unless(
            $this->entitlements->hasEffectiveModuleAccess($project, 'qynva'),
            403,
            'QynVA is not enabled for this workspace.'
        );

        $this->authorize('accessAi', $project);

        return $project;
    }

    protected function requireAdmin(Project $project): void
    {
        $this->authorize('administerAi', $project);
    }

    protected function requireAi(Project $project, Request $request): void
    {
        $permissions = $this->context->effectivePermissions($project, $request->user());
        abort_unless((bool) ($permissions['can_use_ai'] ?? false), 403, 'You do not have permission to use Quenyx AI in this workspace.');
    }

    protected function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observe\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 21 — shared base for the QynSight Operations Intelligence controllers.
 *
 * Centralises the workspace resolution + the full security envelope demanded by the sprint:
 *  - Workspace resolved from a REQUIRED `workspace` UUID (never a numeric id) — reuses the Sprint 20
 *    {@see AiWorkspaceContextResolver}.
 *  - QynSight module entitlement check (`qynsight`).
 *  - Monitoring RBAC (ProjectPolicy `accessAi`/`view`).
 *  - Fine-grained AI capability check (`can_use_ai`) for any AI action.
 *  - The standard `{ success, data }` envelope.
 *
 * Rate limiting + Sanctum auth are applied at the route level (`throttle:ai-workspace`).
 */
abstract class OperationsIntelligenceBaseController extends Controller
{
    public function __construct(
        protected readonly AiWorkspaceContextResolver $context,
        protected readonly EntitlementService $entitlements,
    ) {}

    /**
     * Resolve + authorize the workspace for a read action and ensure QynSight is entitled.
     */
    protected function workspace(Request $request): Project
    {
        $project = $this->context->resolve($request);

        abort_unless(
            $this->entitlements->hasEffectiveModuleAccess($project, 'qynsight'),
            403,
            'QynSight is not enabled for this workspace.'
        );

        $this->authorize('accessAi', $project);

        return $project;
    }

    /**
     * Ensure the caller may run AI actions in this workspace (Operations Intelligence is AI-backed).
     */
    protected function requireAi(Project $project, Request $request): void
    {
        $permissions = $this->context->effectivePermissions($project, $request->user());
        abort_unless((bool) ($permissions['can_use_ai'] ?? false), 403, 'You do not have permission to use Quenyx AI in this workspace.');
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

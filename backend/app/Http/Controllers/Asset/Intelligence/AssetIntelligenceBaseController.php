<?php

declare(strict_types=1);

namespace App\Http\Controllers\Asset\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 22 — shared base for the QynAsset Asset Intelligence controllers.
 *
 * Mirrors the Sprint 21 Operations Intelligence security envelope, scoped to the QynAsset module:
 *  - Workspace resolved from a REQUIRED `workspace` UUID (never a numeric id) via the Sprint 20
 *    {@see AiWorkspaceContextResolver}.
 *  - QynAsset module entitlement check (`qynasset`).
 *  - RBAC via ProjectPolicy (`accessAi`).
 *  - Fine-grained AI capability check (`can_use_ai`) for any AI action.
 *  - The standard `{ success, data }` envelope.
 *
 * Rate limiting + Sanctum auth are applied at the route level (`throttle:ai-workspace`).
 */
abstract class AssetIntelligenceBaseController extends Controller
{
    public function __construct(
        protected readonly AiWorkspaceContextResolver $context,
        protected readonly EntitlementService $entitlements,
    ) {}

    protected function workspace(Request $request): Project
    {
        $project = $this->context->resolve($request);

        abort_unless(
            $this->entitlements->hasEffectiveModuleAccess($project, 'qynasset'),
            403,
            'QynAsset is not enabled for this workspace.'
        );

        $this->authorize('accessAi', $project);

        return $project;
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

    protected function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}

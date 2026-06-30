<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 — shared base for the platform-level Unified AI Workspace controllers.
 *
 * Centralises: resolving the workspace from a `workspace` UUID, the master feature-flag gate,
 * policy authorization (accessAi / administerAi), the fine-grained AI capability check, and the
 * standard `{ success, data }` envelope used across the platform.
 */
abstract class AiWorkspaceBaseController extends Controller
{
    public function __construct(protected readonly AiWorkspaceContextResolver $context) {}

    /**
     * Resolve + authorize the workspace for an access-level (read) action.
     */
    protected function workspace(Request $request, string $ability = 'accessAi'): Project
    {
        abort_unless((bool) config('ai.feature_flags.workspace_enabled', true), 404, 'The AI Workspace is not enabled.');

        $project = $this->context->resolve($request);
        $this->authorize($ability, $project);

        return $project;
    }

    /**
     * Ensure the caller holds a specific fine-grained AI capability (e.g. can_use_ai) on top of the
     * base policy check.
     */
    protected function requireCapability(Project $project, Request $request, string $capability): void
    {
        $permissions = $this->context->effectivePermissions($project, $request->user());
        abort_unless((bool) ($permissions[$capability] ?? false), 403, 'You do not have permission for this AI action.');
    }

    /**
     * @param  mixed  $data
     */
    protected function ok($data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    protected function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}

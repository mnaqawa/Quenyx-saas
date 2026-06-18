<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the workspace has effective entitlement for a module before Observe (or other) APIs run.
 */
class EnsureProjectModuleAccess
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleKey = 'qynsight'): Response
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            return response()->json([
                'success' => false,
                'message' => 'Module unavailable',
                'code' => 'module_unavailable',
            ], 403);
        }

        if (! $this->entitlementService->hasEffectiveModuleAccess($project, $moduleKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Module unavailable',
                'code' => 'module_unavailable',
            ], 403);
        }

        return $next($request);
    }
}

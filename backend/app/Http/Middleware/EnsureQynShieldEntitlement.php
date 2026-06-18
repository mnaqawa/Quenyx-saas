<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * QynShield module entitlement for workspace-scoped compliance corpus APIs.
 */
class EnsureQynShieldEntitlement
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            return $this->deny();
        }

        if (! $this->entitlementService->hasEffectiveModuleAccess($project, 'qynshield')) {
            return $this->deny();
        }

        return $next($request);
    }

    private function deny(): Response
    {
        return response()->json([
            'error' => 'module_not_entitled',
            'module' => 'qynshield',
        ], 403);
    }
}

<?php

use App\Http\Controllers\Compliance\ComplianceMappingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 8 — Cross-Framework Mapping Foundation (workspace-scoped only)
|--------------------------------------------------------------------------
| Read-only, deterministic, UUID-only objective-based mappings. Empty where no
| data exists; no fabricated relationships; confidence is a basis
| (official|manual|derived), never a numeric score. NO AI execution.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit + cache.
|
| Global (non-workspace) mapping endpoints are intentionally NOT registered.
*/

$entityConstraints = [
    'objectiveCode' => '[A-Za-z0-9\-\._:]+',
    'controlCode' => '[A-Za-z0-9\-\._:]+',
    'frameworkKey' => '[A-Za-z0-9\-]+',
];

$registerWorkspaceMappingRoutes = function (string $projectPrefix) use ($entityConstraints): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/mappings")
        ->middleware(['project.qynshield', 'throttle:compliance-mapping-read'])
        ->group(function () use ($entityConstraints) {
            Route::get('/objectives', [ComplianceMappingController::class, 'objectives']);

            Route::get('/objectives/{objectiveCode}', [ComplianceMappingController::class, 'objective'])
                ->where(['objectiveCode' => $entityConstraints['objectiveCode']]);

            Route::get('/controls/{controlCode}', [ComplianceMappingController::class, 'control'])
                ->where(['controlCode' => $entityConstraints['controlCode']]);

            // Registered before the {frameworkKey} route so "compare" is never captured as a key.
            Route::get('/frameworks/compare', [ComplianceMappingController::class, 'compare']);

            Route::get('/frameworks/{frameworkKey}/coverage', [ComplianceMappingController::class, 'coverage'])
                ->where(['frameworkKey' => $entityConstraints['frameworkKey']]);
        });
};

$registerWorkspaceMappingRoutes('projects');
$registerWorkspaceMappingRoutes('workspaces');

<?php

use App\Http\Controllers\Compliance\ComplianceGraphController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 7 — Compliance Knowledge Graph Layer (workspace-scoped only)
|--------------------------------------------------------------------------
| Read-only, deterministic, UUID-only intra-framework graph navigation
| (Domain → Control → Requirement). NO AI execution, vectors, RAG, scoring.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit + cache.
|
| Global (non-workspace) graph endpoints are intentionally NOT registered.
*/

$graphReleaseConstraints = [
    'frameworkKey' => '[A-Za-z0-9\-]+',
    'releaseCode' => '[A-Za-z0-9:\-]+',
];

$graphEntityConstraints = [
    'domainCode' => '[A-Za-z0-9\-\.]+',
    'controlCode' => '[A-Za-z0-9\-\.]+',
    'requirementCode' => '[A-Za-z0-9\-\.]+',
];

$registerWorkspaceGraphRoutes = function (string $projectPrefix) use ($graphReleaseConstraints, $graphEntityConstraints): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/graph")
        ->middleware(['project.qynshield', 'throttle:compliance-graph-read'])
        ->group(function () use ($graphReleaseConstraints, $graphEntityConstraints) {
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}', [ComplianceGraphController::class, 'framework'])
                ->where($graphReleaseConstraints);

            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/domains/{domainCode}', [ComplianceGraphController::class, 'domain'])
                ->where(array_merge($graphReleaseConstraints, ['domainCode' => $graphEntityConstraints['domainCode']]));

            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/controls/{controlCode}', [ComplianceGraphController::class, 'control'])
                ->where(array_merge($graphReleaseConstraints, ['controlCode' => $graphEntityConstraints['controlCode']]));

            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/requirements/{requirementCode}', [ComplianceGraphController::class, 'requirement'])
                ->where(array_merge($graphReleaseConstraints, ['requirementCode' => $graphEntityConstraints['requirementCode']]));
        });
};

$registerWorkspaceGraphRoutes('projects');
$registerWorkspaceGraphRoutes('workspaces');

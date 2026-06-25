<?php

use App\Http\Controllers\Compliance\ComplianceExecutiveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 18 — Executive Demonstration Platform
|--------------------------------------------------------------------------
| Read-only, UUID-only, deterministic executive/investor/customer surface.
| It EXPOSES the intelligence already built by the QCIF engines — NO new
| intelligence, NO fabricated data, NO AI required.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
*/

$registerWorkspaceExecutiveRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/executive")
        ->middleware(['project.qynshield', 'throttle:compliance-executive'])
        ->group(function () {
            Route::get('/dashboard', [ComplianceExecutiveController::class, 'dashboard']);
            Route::get('/scorecard', [ComplianceExecutiveController::class, 'scorecard']);
            Route::get('/timeline', [ComplianceExecutiveController::class, 'timeline']);
            Route::get('/explainability', [ComplianceExecutiveController::class, 'explainability']);
            Route::get('/platform', [ComplianceExecutiveController::class, 'platform']);
        });
};

$registerWorkspaceExecutiveRoutes('projects');
$registerWorkspaceExecutiveRoutes('workspaces');

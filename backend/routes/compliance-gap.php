<?php

use App\Http\Controllers\Compliance\ComplianceGapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 12 — Gap Assessment & Evidence Correlation Engine
|--------------------------------------------------------------------------
| Workspace-scoped, READ-ONLY, deterministic, UUID-only gap assessment. The
| first Compliance Intelligence Engine: correlates evidence to requirements/
| controls/domains/frameworks and evaluates coverage with explainable rules.
| NO AI execution, NO provider calls, NO probabilistic scoring.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging
| + revision-aware (+ evidence fingerprint) cache + rate limiting.
*/

$registerWorkspaceGapRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/gap")
        ->middleware(['project.qynshield', 'throttle:compliance-gap-read'])
        ->group(function () {
            Route::get('/summary', [ComplianceGapController::class, 'summary']);
            Route::get('/domains', [ComplianceGapController::class, 'domains']);
            Route::get('/controls/{controlCode}', [ComplianceGapController::class, 'control']);
            Route::get('/requirements/{requirementCode}', [ComplianceGapController::class, 'requirement']);
            Route::post('/context', [ComplianceGapController::class, 'context']);
        });
};

$registerWorkspaceGapRoutes('projects');
$registerWorkspaceGapRoutes('workspaces');

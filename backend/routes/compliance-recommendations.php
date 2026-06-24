<?php

use App\Http\Controllers\Compliance\ComplianceRecommendationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 13 — Recommendation Engine
|--------------------------------------------------------------------------
| Workspace-scoped, deterministic, UUID-only remediation recommendations
| grounded in gap findings. Rule-based — NO LLM, NO RAG, NO provider calls,
| NO probabilistic scoring, NO automatic remediation.
| GET endpoints are read-only + revision/evidence-aware cached. `generate`
| persists immutable, append-only recommendations and is idempotent.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
*/

$registerWorkspaceRecommendationRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/recommendations")
        ->middleware(['project.qynshield', 'throttle:compliance-recommendation-read'])
        ->group(function () {
            Route::get('/summary', [ComplianceRecommendationController::class, 'summary']);
            Route::get('/controls/{controlCode}', [ComplianceRecommendationController::class, 'control']);
            Route::get('/requirements/{requirementCode}', [ComplianceRecommendationController::class, 'requirement']);
            Route::post('/context', [ComplianceRecommendationController::class, 'context']);
            Route::post('/generate', [ComplianceRecommendationController::class, 'generate']);
        });
};

$registerWorkspaceRecommendationRoutes('projects');
$registerWorkspaceRecommendationRoutes('workspaces');

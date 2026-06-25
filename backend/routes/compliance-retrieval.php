<?php

use App\Http\Controllers\Compliance\ComplianceRetrievalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 15 — Retrieval & RAG Optimization Foundation
|--------------------------------------------------------------------------
| Workspace-scoped deterministic retrieval. Reuses existing AI Skills to
| produce ranked, cited chunks for future RAG. UUID-only, explainable
| ranking — NO vector DB, NO embeddings, NO external retrieval provider,
| NO AI provider calls.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
*/

$registerWorkspaceRetrievalRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/retrieval")
        ->middleware(['project.qynshield', 'throttle:compliance-retrieval'])
        ->group(function () {
            Route::post('/query', [ComplianceRetrievalController::class, 'query']);
        });
};

$registerWorkspaceRetrievalRoutes('projects');
$registerWorkspaceRetrievalRoutes('workspaces');

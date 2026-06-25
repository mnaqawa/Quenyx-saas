<?php

use App\Http\Controllers\Compliance\ComplianceRagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 17 — RAG Runtime & Vector Provider Foundation
|--------------------------------------------------------------------------
| Workspace-scoped RAG context endpoint. Returns the bounded, cited RAG
| CONTEXT package only (never a final AI answer). Flow: Intent → Skills →
| Hybrid Retrieval (deterministic + optional vector) → Reasoning → RAG
| Context. Citation-safe and fallback-safe; UUID-only.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
*/

$registerWorkspaceRagRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/rag")
        ->middleware(['project.qynshield', 'throttle:compliance-rag'])
        ->group(function () {
            Route::post('/query', [ComplianceRagController::class, 'query']);
        });
};

$registerWorkspaceRagRoutes('projects');
$registerWorkspaceRagRoutes('workspaces');

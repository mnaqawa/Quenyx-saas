<?php

use App\Http\Controllers\Compliance\ComplianceCopilotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 14 — Compliance Copilot v0
|--------------------------------------------------------------------------
| Workspace-scoped, UUID-only, citation-enforced Copilot. Orchestrates the
| existing AI Skills and optionally calls a provider through the AI Provider
| Registry — NO direct DB queries, NO direct provider SDK calls, NO RAG, and
| no open-ended chat (closed intent set only).
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
| Prompt logging + conversation persistence are OFF by default.
*/

$registerWorkspaceCopilotRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/copilot")
        ->middleware(['project.qynshield', 'throttle:compliance-copilot'])
        ->group(function () {
            Route::post('/message', [ComplianceCopilotController::class, 'message']);
            Route::get('/conversations', [ComplianceCopilotController::class, 'conversations']);
            Route::get('/conversations/{conversationUuid}', [ComplianceCopilotController::class, 'conversation']);
            Route::post('/conversations/{conversationUuid}/messages', [ComplianceCopilotController::class, 'conversationMessage']);
        });
};

$registerWorkspaceCopilotRoutes('projects');
$registerWorkspaceCopilotRoutes('workspaces');

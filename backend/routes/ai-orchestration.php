<?php

use App\Http\Controllers\Ai\AiOrchestrationController;
use App\Http\Controllers\Ai\AiSkillController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 9 — AI Orchestration Platform (workspace-scoped only)
|--------------------------------------------------------------------------
| Infrastructure endpoints only. AI execution is OFF by default — responses are
| mocked until ai.feature_flags.enabled is true. No business AI (gap assessment,
| evidence intelligence, copilot) is implemented here.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
*/

$registerWorkspaceAiRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/ai")
        ->middleware(['project.qynshield', 'throttle:ai-orchestration'])
        ->group(function () {
            Route::post('/chat', [AiOrchestrationController::class, 'chat']);
            Route::post('/stream', [AiOrchestrationController::class, 'stream']);
        });

    // QCIF Sprint 10 — AI Skills Framework (execution layer; returns SkillResponse only, no AI).
    Route::prefix("{$projectPrefix}/{project}/ai/skills")
        ->middleware(['project.qynshield', 'throttle:ai-skills'])
        ->group(function () {
            Route::get('/', [AiSkillController::class, 'skills']);
            Route::post('/execute', [AiSkillController::class, 'execute']);
        });
};

$registerWorkspaceAiRoutes('projects');
$registerWorkspaceAiRoutes('workspaces');

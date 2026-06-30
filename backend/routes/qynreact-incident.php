<?php

use App\Http\Controllers\Incident\IncidentController;
use App\Http\Controllers\Incident\IncidentIntelligenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 23 — QynReact Incident Workspace
|--------------------------------------------------------------------------
| Workspace-scoped (REQUIRED `workspace` UUID), UUID-only incident workspace. The unified incident
| view REUSES Operations & Asset Intelligence (and Automation) through the AI Adapter Registry via the
| cross-module orchestrator — no module branching. AI surfaces (copilot, recommend, postmortem) reuse
| the shared Quenyx AI runtime and require the `can_use_ai` capability. Auth: sanctum (outer group) +
| `throttle:ai-workspace`; RBAC via `accessAi`.
*/

Route::prefix('qynreact')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::post('/incidents', [IncidentController::class, 'store']);
        Route::get('/incidents/{uuid}', [IncidentController::class, 'show']);
        Route::match(['put', 'patch'], '/incidents/{uuid}', [IncidentController::class, 'update']);
        Route::post('/incidents/{uuid}/timeline', [IncidentController::class, 'addTimeline']);

        // Incident Intelligence (AI surface).
        Route::post('/incidents/{uuid}/copilot', [IncidentIntelligenceController::class, 'copilot']);
        Route::post('/incidents/{uuid}/recommend', [IncidentIntelligenceController::class, 'recommend']);
        Route::post('/incidents/{uuid}/postmortem', [IncidentIntelligenceController::class, 'postmortem']);
    });

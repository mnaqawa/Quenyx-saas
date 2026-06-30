<?php

use App\Http\Controllers\Automation\AutomationApprovalController;
use App\Http\Controllers\Automation\AutomationExecutionController;
use App\Http\Controllers\Automation\AutomationIntelligenceController;
use App\Http\Controllers\Automation\AutomationLearningController;
use App\Http\Controllers\Automation\AutomationRegistryController;
use App\Http\Controllers\Automation\AutomationRunbookController;
use App\Http\Controllers\Automation\AutomationWorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 23 — QynRun Enterprise Automation Platform
|--------------------------------------------------------------------------
| Workspace-scoped (REQUIRED `workspace` UUID), UUID-only, registry-driven automation. The Execution
| Engine resolves adapters ONLY through the Automation Registry — there is no hardcoded execution path
| and no duplicated automation logic. SAFE BY DEFAULT: every action plans (dry-run) unless live
| execution is enabled, and every live action requires explicit approval. AI surfaces reuse the shared
| Quenyx AI runtime (ModuleAiNarrator). Auth: sanctum (outer group) + `throttle:ai-workspace`. RBAC:
| reads/dry-run require `accessAi`; approvals, live runs, rollback, and deletes require `administerAi`.
*/

Route::prefix('qynrun')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        // Registry discovery (adapters + action catalog).
        Route::get('/automation/adapters', [AutomationRegistryController::class, 'adapters']);
        Route::get('/automation/actions', [AutomationRegistryController::class, 'actions']);

        // Workflows.
        Route::get('/automation/workflows', [AutomationWorkflowController::class, 'index']);
        Route::post('/automation/workflows', [AutomationWorkflowController::class, 'store']);
        Route::get('/automation/workflows/{uuid}', [AutomationWorkflowController::class, 'show']);
        Route::match(['put', 'patch'], '/automation/workflows/{uuid}', [AutomationWorkflowController::class, 'update']);
        Route::delete('/automation/workflows/{uuid}', [AutomationWorkflowController::class, 'destroy']);
        Route::post('/automation/workflows/{uuid}/run', [AutomationWorkflowController::class, 'run']);

        // Runbooks.
        Route::get('/automation/runbooks', [AutomationRunbookController::class, 'index']);
        Route::post('/automation/runbooks', [AutomationRunbookController::class, 'store']);
        Route::get('/automation/runbooks/{uuid}', [AutomationRunbookController::class, 'show']);
        Route::match(['put', 'patch'], '/automation/runbooks/{uuid}', [AutomationRunbookController::class, 'update']);
        Route::delete('/automation/runbooks/{uuid}', [AutomationRunbookController::class, 'destroy']);
        Route::post('/automation/runbooks/{uuid}/run', [AutomationRunbookController::class, 'run']);

        // Executions + rollback + feedback.
        Route::get('/automation/executions', [AutomationExecutionController::class, 'index']);
        Route::post('/automation/executions', [AutomationExecutionController::class, 'store']);
        Route::get('/automation/executions/{uuid}', [AutomationExecutionController::class, 'show']);
        Route::post('/automation/executions/{uuid}/rollback', [AutomationExecutionController::class, 'rollback']);
        Route::post('/automation/executions/{uuid}/feedback', [AutomationExecutionController::class, 'feedback']);

        // Approvals.
        Route::get('/automation/approvals', [AutomationApprovalController::class, 'index']);
        Route::post('/automation/approvals/{uuid}/decide', [AutomationApprovalController::class, 'decide']);

        // Automation Learning (auditable aggregated outcomes).
        Route::get('/automation/learning', [AutomationLearningController::class, 'stats']);

        // Automation Intelligence (AI surface — reuses the shared Quenyx AI runtime).
        Route::get('/intelligence/overview', [AutomationIntelligenceController::class, 'overview']);
        Route::post('/intelligence/copilot', [AutomationIntelligenceController::class, 'copilot']);
        Route::post('/intelligence/runbooks/suggest', [AutomationIntelligenceController::class, 'suggestRunbook']);
        Route::post('/intelligence/executions/{uuid}/explain', [AutomationIntelligenceController::class, 'explainExecution']);
    });

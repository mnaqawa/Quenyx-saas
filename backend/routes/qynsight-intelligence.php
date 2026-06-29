<?php

use App\Http\Controllers\Observe\Intelligence\AlertIntelligenceController;
use App\Http\Controllers\Observe\Intelligence\OperationsIntelligenceController;
use App\Http\Controllers\Observe\Intelligence\ResourceIntelligenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 21 — QynSight Operations Intelligence
|--------------------------------------------------------------------------
| Workspace-scoped (by REQUIRED `workspace` UUID — never a numeric id), UUID-only AI surface that
| turns QynSight monitoring data into explainable operational intelligence. REUSES the Sprint 20
| Quenyx AI runtime (provider registry, prompt orchestrator, conversation surface, audit) via the
| Operations Intelligence services — no AI logic is duplicated. Auth: sanctum (outer group) +
| `throttle:ai-workspace`. Every action checks QynSight entitlement, monitoring RBAC, and the
| `can_use_ai` capability, and is audited + provider-logged.
*/

Route::prefix('qynsight/intelligence')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        // Dashboard + copilot + recommendations.
        Route::get('/overview', [OperationsIntelligenceController::class, 'overview']);
        Route::post('/copilot', [OperationsIntelligenceController::class, 'copilot']);
        Route::get('/recommendations', [OperationsIntelligenceController::class, 'recommendations']);

        // Alert intelligence + incident timeline (UUID-only).
        Route::post('/alerts/{uuid}/explain', [AlertIntelligenceController::class, 'explain']);
        Route::post('/alerts/{uuid}/investigate', [AlertIntelligenceController::class, 'investigate']);
        Route::get('/incidents/{uuid}/timeline', [AlertIntelligenceController::class, 'timeline']);

        // Contextual resource intelligence (UUID-only).
        Route::post('/hosts/{uuid}/explain', [ResourceIntelligenceController::class, 'explainHost']);
        Route::post('/services/{uuid}/analyze', [ResourceIntelligenceController::class, 'analyzeService']);
        Route::post('/capacity/{uuid}/predict', [ResourceIntelligenceController::class, 'predictCapacity']);
        Route::post('/infrastructure/{uuid}/impact', [ResourceIntelligenceController::class, 'impact']);
    });

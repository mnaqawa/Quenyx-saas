<?php

use App\Http\Controllers\Knowledge\EnterpriseSearchController;
use App\Http\Controllers\Knowledge\GlobalTimelineController;
use App\Http\Controllers\Knowledge\KnowledgeController;
use App\Http\Controllers\Knowledge\KnowledgeGraphController;
use App\Http\Controllers\Knowledge\KnowledgeIntelligenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 24 — QynKnow Enterprise Knowledge Platform
|--------------------------------------------------------------------------
| Workspace-scoped (REQUIRED `workspace` UUID), UUID-only, registry-driven knowledge. Enterprise Search
| and the Knowledge Assistant consume sources ONLY through the Knowledge Source Registry — no provider
| branching. The Knowledge Graph v2 and Global Timeline are deterministic read-models over real rows.
| AI surfaces reuse the shared Quenyx AI runtime (ModuleAiNarrator). Auth: sanctum (outer group) +
| `throttle:ai-workspace`. RBAC: reads/search require `accessAi`; document writes require `administerAi`;
| AI surfaces require `can_use_ai`.
*/

Route::prefix('qynknow')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        // Knowledge Source Registry discovery.
        Route::get('/sources', [KnowledgeController::class, 'sources']);

        // Knowledge documents (Internal Knowledge Base).
        Route::get('/documents', [KnowledgeController::class, 'index']);
        Route::post('/documents', [KnowledgeController::class, 'store']);
        Route::get('/documents/{uuid}', [KnowledgeController::class, 'show']);
        Route::match(['put', 'patch'], '/documents/{uuid}', [KnowledgeController::class, 'update']);
        Route::delete('/documents/{uuid}', [KnowledgeController::class, 'destroy']);

        // Enterprise Search (keyword + semantic), Global Timeline, Knowledge Graph v2.
        Route::get('/search', [EnterpriseSearchController::class, 'search']);
        Route::get('/timeline', [GlobalTimelineController::class, 'index']);
        Route::get('/graph', [KnowledgeGraphController::class, 'index']);

        // Knowledge Assistant (AI surface — reuses the shared Quenyx AI runtime).
        Route::get('/intelligence/overview', [KnowledgeIntelligenceController::class, 'overview']);
        Route::post('/intelligence/copilot', [KnowledgeIntelligenceController::class, 'copilot']);
        Route::post('/intelligence/related', [KnowledgeIntelligenceController::class, 'related']);
        Route::post('/intelligence/draft', [KnowledgeIntelligenceController::class, 'draft']);
        Route::post('/intelligence/documents/{uuid}/explain', [KnowledgeIntelligenceController::class, 'explain']);
        Route::post('/intelligence/documents/{uuid}/summarize', [KnowledgeIntelligenceController::class, 'summarize']);
    });

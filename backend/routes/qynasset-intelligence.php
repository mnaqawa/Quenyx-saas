<?php

use App\Http\Controllers\Asset\Intelligence\AssetIntelligenceController;
use App\Http\Controllers\Asset\Intelligence\AssetResourceIntelligenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 22 — QynAsset Asset Intelligence
|--------------------------------------------------------------------------
| Workspace-scoped (by REQUIRED `workspace` UUID — never a numeric id), UUID-only AI surface that
| turns the discovered asset inventory into explainable asset intelligence. REUSES the shared Quenyx
| AI runtime via the shared ModuleAiNarrator and the QynAsset domain services — no AI logic, provider
| logic, or orchestration is duplicated. Auth: sanctum (outer group) + `throttle:ai-workspace`. Every
| action checks QynAsset entitlement, RBAC, and the `can_use_ai` capability, and is audited +
| provider-logged. Asset intelligence never fabricates inventory, lifecycle, or license data.
*/

Route::prefix('qynasset/intelligence')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        // Dashboard + copilot + recommendations.
        Route::get('/overview', [AssetIntelligenceController::class, 'overview']);
        Route::post('/copilot', [AssetIntelligenceController::class, 'copilot']);
        Route::get('/recommendations', [AssetIntelligenceController::class, 'recommendations']);

        // Contextual per-asset intelligence (UUID-only).
        Route::post('/assets/{uuid}/explain', [AssetResourceIntelligenceController::class, 'explain']);
        Route::post('/assets/{uuid}/dependencies', [AssetResourceIntelligenceController::class, 'dependencies']);
        Route::post('/assets/{uuid}/lifecycle', [AssetResourceIntelligenceController::class, 'lifecycle']);
        Route::post('/assets/{uuid}/impact', [AssetResourceIntelligenceController::class, 'impact']);

        // License Intelligence (workspace-level — no per-license entity exists).
        Route::post('/licenses/review', [AssetResourceIntelligenceController::class, 'reviewLicense']);
    });

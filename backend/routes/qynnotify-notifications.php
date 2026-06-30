<?php

use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Notification\NotificationIntelligenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 24 — QynNotify Notification Center (Notification Intelligence)
|--------------------------------------------------------------------------
| Workspace-scoped (REQUIRED `workspace` UUID), UUID-only notifications. Ingestion performs deterministic
| deduplication, correlation, urgency scoring, recipient/channel selection, and escalation over REAL
| workspace members — no fake routing. AI digests/summaries reuse the shared Quenyx AI runtime. Auth:
| sanctum (outer group) + `throttle:ai-workspace`. RBAC: `accessAi` for reads/writes; `can_use_ai` for AI.
*/

Route::prefix('qynnotify')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications', [NotificationController::class, 'store']);
        Route::post('/notifications/{uuid}/read', [NotificationController::class, 'markRead']);

        // Notification Intelligence (AI surface).
        Route::post('/intelligence/digest', [NotificationIntelligenceController::class, 'digest']);
        Route::post('/intelligence/executive', [NotificationIntelligenceController::class, 'executive']);
        Route::post('/intelligence/copilot', [NotificationIntelligenceController::class, 'copilot']);
    });

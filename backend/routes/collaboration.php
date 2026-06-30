<?php

use App\Http\Controllers\Collaboration\CollaborationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 24 — Collaboration Platform (shared, reusable by every module)
|--------------------------------------------------------------------------
| Workspace-scoped (REQUIRED `workspace` UUID), UUID-only. Comments, mentions, watchers, assignments,
| and task ownership on ANY entity (incident/ticket/document/execution/asset/…). Platform-wide: available
| to any workspace member (`accessAi`), no per-module entitlement — every module reuses this one layer.
| Auth: sanctum (outer group) + `throttle:ai-workspace`.
*/

Route::prefix('collaboration')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        Route::get('/thread', [CollaborationController::class, 'thread']);
        Route::post('/comments', [CollaborationController::class, 'comment']);
        Route::post('/participants', [CollaborationController::class, 'addParticipant']);
        Route::delete('/participants', [CollaborationController::class, 'removeParticipant']);
    });

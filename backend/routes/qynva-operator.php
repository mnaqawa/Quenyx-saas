<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\AnalyticsController;
use App\Http\Controllers\Platform\EventBusController;
use App\Http\Controllers\Platform\ExecutiveController;
use App\Http\Controllers\Platform\OperatorController;
use App\Http\Controllers\Platform\PlatformHealthController;
use Illuminate\Support\Facades\Route;

/**
 * Sprint 25 — QynVA Enterprise AI Operator + Enterprise Intelligence surfaces.
 *
 * Workspace-scoped, UUID-only. Reads require `accessAi`; AI surfaces require `can_use_ai`; platform
 * operations (health, event bus) require `administerAi`. Required inside the `auth:sanctum` group in
 * api.php; throttled by the shared AI workspace limiter.
 */
Route::prefix('qynva')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        // Enterprise AI Operator
        Route::get('/operator/capabilities', [OperatorController::class, 'capabilities']);
        Route::post('/operator/operate', [OperatorController::class, 'operate']);

        // Executive Intelligence
        Route::get('/executive', [ExecutiveController::class, 'dashboard']);
        Route::post('/executive/summary', [ExecutiveController::class, 'summary']);

        // Enterprise Analytics
        Route::get('/analytics', [AnalyticsController::class, 'index']);

        // Platform Health (privileged)
        Route::get('/health', [PlatformHealthController::class, 'index']);

        // Platform Event Bus introspection (privileged)
        Route::get('/events', [EventBusController::class, 'describe']);
    });

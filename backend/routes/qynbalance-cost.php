<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\CostController;
use Illuminate\Support\Facades\Route;

/**
 * Sprint 25 — QynBalance Enterprise Cost Intelligence.
 *
 * Workspace-scoped, UUID-only. Overview requires `accessAi`; the cost copilot requires `can_use_ai`.
 * Required inside the `auth:sanctum` group in api.php; throttled by the shared AI workspace limiter.
 */
Route::prefix('qynbalance')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        Route::get('/cost/overview', [CostController::class, 'overview']);
        Route::post('/cost/copilot', [CostController::class, 'copilotAction']);
    });

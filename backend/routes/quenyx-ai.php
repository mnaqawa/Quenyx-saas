<?php

use App\Http\Controllers\QuenyxAI\QuenyxAiPlatformController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 19 — Quenyx AI Platform Foundation
|--------------------------------------------------------------------------
| Platform-level (not tenant-scoped) capability catalog for the shared
| Quenyx AI Platform. Read-only and dynamically generated from the
| registered module adapters + generic AI runtime registries.
| Auth: sanctum (outer group). No business logic; no AI execution.
*/

Route::get('/ai/platform/capabilities', [QuenyxAiPlatformController::class, 'capabilities']);

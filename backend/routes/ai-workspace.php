<?php

use App\Http\Controllers\Ai\Workspace\AiCapabilityController;
use App\Http\Controllers\Ai\Workspace\AiConversationController;
use App\Http\Controllers\Ai\Workspace\AiPermissionController;
use App\Http\Controllers\Ai\Workspace\AiProviderController;
use App\Http\Controllers\Ai\Workspace\AiPromptTemplateController;
use App\Http\Controllers\Ai\Workspace\AiWorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 20 — Unified AI Workspace (platform-level, NOT a business module)
|--------------------------------------------------------------------------
| Platform-level AI surface that sits beside Dashboard / Workspaces /
| Integrations. Routes are flat under /api/ai/* (matching the existing
| /api/ai/platform/capabilities convention) and are scoped to a workspace by
| a REQUIRED `workspace` UUID (query for reads, body for writes) — never a
| numeric id. Every controller resolves + authorizes the workspace via
| ProjectPolicy (accessAi for reads, administerAi for admin) plus a
| fine-grained AI capability check. Auth: sanctum (outer group) + a dedicated
| rate limiter. Reuses the existing AI runtime; no QynShield logic duplicated.
*/

Route::prefix('ai')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        // Workspace summary + derived usage / cost / activity / notifications (real data only).
        Route::get('/workspace/summary', [AiWorkspaceController::class, 'summary']);
        Route::get('/usage', [AiWorkspaceController::class, 'usage']);
        Route::get('/costs', [AiWorkspaceController::class, 'costs']);
        Route::get('/activity', [AiWorkspaceController::class, 'activity']);
        Route::get('/notifications', [AiWorkspaceController::class, 'notifications']);

        // Conversations (UUID-only). Sending a message reuses the shared AI runtime.
        Route::get('/conversations', [AiConversationController::class, 'index']);
        Route::post('/conversations', [AiConversationController::class, 'store']);
        Route::get('/conversations/{uuid}', [AiConversationController::class, 'show']);
        Route::post('/conversations/{uuid}/messages', [AiConversationController::class, 'storeMessage']);

        // Capability explorer + skills browser (dynamic Quenyx AI Platform catalog).
        Route::get('/capabilities', [AiCapabilityController::class, 'capabilities']);
        Route::get('/skills', [AiCapabilityController::class, 'skills']);

        // Prompt templates (CRUD).
        Route::get('/prompt-templates', [AiPromptTemplateController::class, 'index']);
        Route::post('/prompt-templates', [AiPromptTemplateController::class, 'store']);
        Route::put('/prompt-templates/{uuid}', [AiPromptTemplateController::class, 'update']);
        Route::delete('/prompt-templates/{uuid}', [AiPromptTemplateController::class, 'destroy']);

        // Provider settings (secrets are write-only + encrypted; admin only to update).
        Route::get('/providers', [AiProviderController::class, 'index']);
        Route::put('/providers/{uuid}/settings', [AiProviderController::class, 'updateSettings']);
        // Real readiness probe (executable providers run health(); others report not_executable).
        Route::post('/providers/{uuid}/test', [AiProviderController::class, 'test']);

        // AI permission matrix (admin only).
        Route::get('/permissions', [AiPermissionController::class, 'index']);
        Route::put('/permissions', [AiPermissionController::class, 'update']);
    });

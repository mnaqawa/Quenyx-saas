<?php

use App\Http\Controllers\Support\TicketController;
use App\Http\Controllers\Support\TicketIntelligenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sprint 24 — QynSupport Service Desk (Ticket Intelligence)
|--------------------------------------------------------------------------
| Workspace-scoped (REQUIRED `workspace` UUID), UUID-only tickets. AI triage is EVIDENCE-BASED and
| editable — category/priority/impact/assignee/SLA suggestions plus related incidents/assets/runbooks
| are never auto-applied. AI surfaces reuse the shared Quenyx AI runtime. Auth: sanctum (outer group) +
| `throttle:ai-workspace`. RBAC: `accessAi` for reads/writes; `can_use_ai` for AI surfaces.
*/

Route::prefix('qynsupport')
    ->middleware('throttle:ai-workspace')
    ->group(function (): void {
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/{uuid}', [TicketController::class, 'show']);
        Route::match(['put', 'patch'], '/tickets/{uuid}', [TicketController::class, 'update']);

        // Ticket Intelligence (AI surface).
        Route::post('/tickets/{uuid}/intelligence/analyze', [TicketIntelligenceController::class, 'analyze']);
        Route::post('/tickets/{uuid}/intelligence/copilot', [TicketIntelligenceController::class, 'copilot']);
    });

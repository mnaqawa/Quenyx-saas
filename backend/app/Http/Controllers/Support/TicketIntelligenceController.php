<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Support\Intelligence\TicketIntelligenceService;
use App\Services\Support\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Ticket Intelligence API (QynSupport). Evidence-based triage + ticket copilot. Requires
 * the `can_use_ai` capability; suggestions are editable and never auto-applied.
 */
class TicketIntelligenceController extends SupportBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly TicketIntelligenceService $intelligence,
        private readonly TicketService $tickets,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function analyze(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $ticket = $this->tickets->find($project, $uuid);
        abort_if($ticket === null, 404, 'Ticket not found.');

        return $this->ok($this->intelligence->analyze($project, $request->user(), $ticket));
    }

    public function copilot(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $ticket = $this->tickets->find($project, $uuid);
        abort_if($ticket === null, 404, 'Ticket not found.');

        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->intelligence->copilot($project, $request->user(), $ticket, (string) $data['message'], $data['conversation'] ?? null));
    }
}

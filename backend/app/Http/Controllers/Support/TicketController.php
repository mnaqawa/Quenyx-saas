<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Support\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Service Desk ticket CRUD (QynSupport).
 */
class TicketController extends SupportBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly TicketService $tickets,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['tickets' => $this->tickets->list($project, [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
        ])]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $ticket = $this->tickets->find($project, $uuid);
        abort_if($ticket === null, 404, 'Ticket not found.');

        return $this->ok($this->tickets->detail($ticket));
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'subject' => 'required|string|max:250',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:64',
            'priority' => 'nullable|in:critical,high,medium,low',
            'impact' => 'nullable|in:org,service,team,individual',
            'source' => 'nullable|in:manual,email,api,alert',
            'incident_uuid' => 'nullable|uuid',
            'asset_uuid' => 'nullable|uuid',
        ]);

        $ticket = $this->tickets->create($project, $request->user(), $data);

        return $this->ok($this->tickets->detail($ticket), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $ticket = $this->tickets->find($project, $uuid);
        abort_if($ticket === null, 404, 'Ticket not found.');

        $data = $request->validate([
            'workspace' => 'required|string',
            'subject' => 'sometimes|string|max:250',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:64',
            'priority' => 'nullable|in:critical,high,medium,low',
            'impact' => 'nullable|in:org,service,team,individual',
            'status' => 'nullable|in:open,in_progress,pending,resolved,closed',
            'assignee_uuid' => 'nullable|uuid',
            'sla_due_at' => 'nullable|date',
        ]);

        return $this->ok($this->tickets->detail($this->tickets->update($project, $request->user(), $ticket, $data)));
    }
}

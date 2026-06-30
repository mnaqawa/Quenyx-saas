<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Project;
use App\Models\Support\Ticket;
use App\Models\User;
use App\Services\Platform\PlatformAuditLogger;

/**
 * Sprint 24 — Service Desk domain service (QynSupport): ticket CRUD + lifecycle. Workspace-scoped,
 * UUID-addressed, audited. AI suggestions are stored on the ticket but only operator-confirmed fields
 * are authoritative — nothing is auto-applied.
 */
class TicketService
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, array $filters = []): array
    {
        return Ticket::with(['assignee', 'requester'])
            ->where('project_id', $project->id)
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['priority']), fn ($q) => $q->where('priority', $filters['priority']))
            ->orderByDesc('updated_at')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (Ticket $t): array => $this->summary($t))
            ->all();
    }

    public function find(Project $project, string $uuid): ?Ticket
    {
        return Ticket::with(['assignee', 'requester'])->where('project_id', $project->id)->where('uuid', $uuid)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, ?User $user, array $data): Ticket
    {
        $ticket = Ticket::create([
            'project_id' => $project->id,
            'requested_by' => $user?->id,
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'impact' => $data['impact'] ?? null,
            'status' => 'open',
            'source' => $data['source'] ?? 'manual',
            'incident_uuid' => $data['incident_uuid'] ?? null,
            'asset_uuid' => $data['asset_uuid'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);

        $this->audit->log($user, $project, 'ticket_created', ['uuid' => $ticket->uuid, 'reference' => $ticket->reference]);

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, ?User $user, Ticket $ticket, array $data): Ticket
    {
        $ticket->fill(array_filter([
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'priority' => $data['priority'] ?? null,
            'impact' => $data['impact'] ?? null,
            'status' => $data['status'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('assignee_uuid', $data)) {
            $assignee = $data['assignee_uuid'] ? User::where('uuid', $data['assignee_uuid'])->first() : null;
            $ticket->assigned_to = $assignee?->id;
        }
        if (isset($data['sla_due_at'])) {
            $ticket->sla_due_at = $data['sla_due_at'];
        }
        if (($data['status'] ?? null) === 'resolved' && $ticket->resolved_at === null) {
            $ticket->resolved_at = now();
        }

        $ticket->save();
        $this->audit->log($user, $project, 'ticket_updated', ['uuid' => $ticket->uuid, 'status' => $ticket->status]);

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Ticket $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'impact' => $ticket->impact,
            'status' => $ticket->status,
            'assignee' => $ticket->assignee ? ['uuid' => $ticket->assignee->uuid, 'name' => $ticket->assignee->name] : null,
            'sla_due_at' => optional($ticket->sla_due_at)->toIso8601String(),
            'updated_at' => optional($ticket->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Ticket $ticket): array
    {
        return array_merge($this->summary($ticket), [
            'description' => $ticket->description,
            'source' => $ticket->source,
            'incident_uuid' => $ticket->incident_uuid,
            'asset_uuid' => $ticket->asset_uuid,
            'requester' => $ticket->requester ? ['uuid' => $ticket->requester->uuid, 'name' => $ticket->requester->name] : null,
            'ai_suggestions' => (array) ($ticket->ai_suggestions ?? []),
            'metadata' => (array) ($ticket->metadata ?? []),
            'created_at' => optional($ticket->created_at)->toIso8601String(),
            'resolved_at' => optional($ticket->resolved_at)->toIso8601String(),
        ]);
    }
}

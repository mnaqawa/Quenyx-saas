<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Automation\AutomationApproval;
use App\Models\Automation\AutomationExecution;
use App\Models\Incident\Incident;
use App\Models\Incident\IncidentTimelineEntry;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Notification\Notification;
use App\Models\Project;
use App\Models\Support\Ticket;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 24 — the platform-wide Global Timeline.
 *
 * A deterministic READ-MODEL that merges chronological events from every domain (alerts/incidents,
 * asset & automation activity, tickets, notifications, approvals, knowledge updates, resolutions) into
 * one connected stream. It reuses the REAL rows already stored by each module — nothing is duplicated
 * or fabricated. Adding a source is one entry here, not a branch.
 */
class GlobalTimelineService
{
    /**
     * @param  array<string, mixed>  $filters  limit, types (list<string>)
     * @return array<string, mixed>
     */
    public function build(Project $project, array $filters = []): array
    {
        $limit = $this->clampLimit((int) ($filters['limit'] ?? config('knowledge.timeline.default_limit', 100)));
        $types = (array) ($filters['types'] ?? []);

        $events = [];

        if ($this->wants($types, 'incident') && Schema::hasTable('incidents')) {
            foreach (Incident::where('project_id', $project->id)->latest('opened_at')->limit($limit)->get() as $i) {
                $events[] = $this->event($i->opened_at ?? $i->created_at, 'incident', 'qynreact', 'Incident opened: '.$i->title, $i->severity.' · '.$i->status, 'incident', $i->uuid);
                if ($i->resolved_at) {
                    $events[] = $this->event($i->resolved_at, 'resolution', 'qynreact', 'Incident resolved: '.$i->title, (string) $i->resolution, 'incident', $i->uuid);
                }
            }
        }

        if ($this->wants($types, 'incident_event') && Schema::hasTable('incident_timeline_entries')) {
            foreach (IncidentTimelineEntry::where('project_id', $project->id)->latest('at')->limit($limit)->get() as $e) {
                $events[] = $this->event($e->at, $e->type, 'qynreact', $e->description, (string) $e->category, 'incident', (string) $e->incident_id);
            }
        }

        if ($this->wants($types, 'automation') && Schema::hasTable('automation_executions')) {
            foreach (AutomationExecution::where('project_id', $project->id)->latest()->limit($limit)->get() as $x) {
                $events[] = $this->event($x->created_at, 'automation', 'qynrun', 'Execution '.$x->status.': '.$x->adapter_key, (string) $x->action_key, 'execution', $x->uuid);
            }
        }

        if ($this->wants($types, 'approval') && Schema::hasTable('automation_approvals')) {
            foreach (AutomationApproval::where('project_id', $project->id)->latest()->limit($limit)->get() as $a) {
                $events[] = $this->event($a->decided_at ?? $a->created_at, 'approval', 'qynrun', 'Approval '.$a->status, (string) $a->reason, 'approval', $a->uuid);
            }
        }

        if ($this->wants($types, 'ticket') && Schema::hasTable('tickets')) {
            foreach (Ticket::where('project_id', $project->id)->latest()->limit($limit)->get() as $tk) {
                $events[] = $this->event($tk->created_at, 'ticket', 'qynsupport', 'Ticket '.$tk->reference.': '.$tk->subject, $tk->priority.' · '.$tk->status, 'ticket', $tk->uuid);
            }
        }

        if ($this->wants($types, 'notification') && Schema::hasTable('notifications')) {
            foreach (Notification::where('project_id', $project->id)->latest()->limit($limit)->get() as $n) {
                $events[] = $this->event($n->created_at, 'notification', 'qynnotify', $n->title, $n->severity.' · '.$n->status, 'notification', $n->uuid);
            }
        }

        if ($this->wants($types, 'knowledge') && Schema::hasTable('knowledge_documents')) {
            foreach (KnowledgeDocument::where('project_id', $project->id)->latest('updated_at')->limit($limit)->get() as $d) {
                $events[] = $this->event($d->updated_at ?? $d->indexed_at, 'knowledge', 'qynknow', 'Knowledge: '.$d->title, (string) $d->category, 'document', $d->uuid);
            }
        }

        usort($events, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return [
            'total' => count($events),
            'events' => array_slice($events, 0, $limit),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(mixed $at, string $type, string $module, string $title, string $description, string $entityType, string $entityUuid): array
    {
        return [
            'at' => $at ? $at->toIso8601String() : null,
            'type' => $type,
            'module' => $module,
            'title' => $title,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
        ];
    }

    /**
     * @param  list<string>  $types
     */
    private function wants(array $types, string $type): bool
    {
        return $types === [] || in_array($type, $types, true);
    }

    private function clampLimit(int $limit): int
    {
        $max = (int) config('knowledge.timeline.max_limit', 300);

        return max(1, min($limit, $max));
    }
}

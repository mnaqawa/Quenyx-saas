<?php

declare(strict_types=1);

namespace App\Services\Incident;

use App\Models\Incident\Incident;
use App\Models\Incident\IncidentTimelineEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Automation\ExecutionHistory;
use App\Services\Platform\EventBus\PlatformEventNames;
use App\Services\Platform\EventBus\PublishesPlatformEvents;

/**
 * Sprint 23 — QynReact Incident Workspace.
 *
 * Manages incidents and assembles the unified incident view: timeline, linked assets + monitoring
 * (via the cross-module orchestrator — reusing Operations & Asset Intelligence without branching),
 * linked automation executions, evidence, resolution, and postmortem. All deterministic; the AI layer
 * narrates this through {@see \App\Services\Incident\Intelligence\QynReactIntelligenceService}.
 */
class IncidentService
{
    use PublishesPlatformEvents;

    public function __construct(
        private readonly CrossModuleOrchestrator $orchestrator,
        private readonly ExecutionHistory $executions,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, ?string $status = null): array
    {
        $query = Incident::where('project_id', $project->id)->orderByDesc('opened_at');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->limit(200)->get()->map(fn (Incident $i): array => $this->summary($i))->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, ?User $user, array $data): Incident
    {
        $incident = Incident::create([
            'project_id' => $project->id,
            'opened_by' => $user?->id,
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? 'medium',
            'status' => 'open',
            'source' => $data['source'] ?? 'manual',
            'alert_uuid' => $data['alert_uuid'] ?? null,
            'asset_uuid' => $data['asset_uuid'] ?? null,
        ]);

        $this->addTimeline($incident, $user, 'status_change', 'opened', 'Incident opened.', [
            'severity' => $incident->severity,
            'source' => $incident->source,
        ]);

        $this->publishPlatformEvent(PlatformEventNames::INCIDENT_OPENED, $project, $user, [
            'incident_uuid' => $incident->uuid,
            'title' => $incident->title,
            'severity' => $incident->severity,
            'source' => $incident->source,
            'alert_uuid' => $incident->alert_uuid,
            'asset_uuid' => $incident->asset_uuid,
        ]);

        return $incident;
    }

    public function find(Project $project, string $uuid): ?Incident
    {
        return Incident::where('project_id', $project->id)->where('uuid', $uuid)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Incident $incident, ?User $user, array $data): Incident
    {
        $changes = [];
        foreach (['title', 'description', 'severity', 'status', 'resolution'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if (($changes['status'] ?? null) === 'resolved' && $incident->resolved_at === null) {
            $changes['resolved_at'] = now();
        }

        $incident->update($changes);

        if (isset($changes['status'])) {
            $this->addTimeline($incident, $user, 'status_change', 'status', 'Status changed to '.$changes['status'].'.', $changes);
        }

        $project = $incident->project;
        if ($project !== null) {
            $resolved = ($changes['status'] ?? null) === 'resolved';
            $this->publishPlatformEvent(
                $resolved ? PlatformEventNames::INCIDENT_RESOLVED : PlatformEventNames::INCIDENT_UPDATED,
                $project,
                $user,
                array_merge(['incident_uuid' => $incident->uuid], array_intersect_key($changes, array_flip(['status', 'severity']))),
            );
        }

        return $incident->fresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function addTimeline(Incident $incident, ?User $user, string $type, ?string $category, string $description, array $metadata = []): IncidentTimelineEntry
    {
        return IncidentTimelineEntry::create([
            'incident_id' => $incident->id,
            'project_id' => $incident->project_id,
            'created_by' => $user?->id,
            'at' => now(),
            'type' => $type,
            'category' => $category,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * The unified incident workspace aggregate.
     *
     * @return array<string, mixed>
     */
    public function workspace(Incident $incident): array
    {
        $project = $incident->project;

        return [
            'incident' => $this->summary($incident),
            'description' => $incident->description,
            'timeline' => $incident->timeline()->get()->map(fn (IncidentTimelineEntry $e): array => [
                'at' => optional($e->at)->toIso8601String(),
                'type' => $e->type,
                'category' => $e->category,
                'description' => $e->description,
                'metadata' => $e->metadata,
            ])->all(),
            // Reuse Operations & Asset Intelligence via the adapter registry (no module branching).
            'cross_module' => $this->orchestrator->gather($project, ['qynreact']),
            'automation' => $this->executions->list($project, ['incident_id' => $incident->id, 'limit' => 50]),
            'evidence' => $incident->timeline()->where('type', 'evidence')->get()->map(fn (IncidentTimelineEntry $e): array => [
                'at' => optional($e->at)->toIso8601String(),
                'description' => $e->description,
                'metadata' => $e->metadata,
            ])->all(),
            'knowledge' => [
                'available' => false,
                'note' => 'Knowledge base integration (QynKnow) is not yet connected; related articles are not collected.',
            ],
            'resolution' => $incident->resolution,
            'postmortem' => $incident->postmortem,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Incident $incident): array
    {
        return [
            'uuid' => $incident->uuid,
            'title' => $incident->title,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'source' => $incident->source,
            'alert_uuid' => $incident->alert_uuid,
            'asset_uuid' => $incident->asset_uuid,
            'opened_at' => optional($incident->opened_at)->toIso8601String(),
            'resolved_at' => optional($incident->resolved_at)->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveAlertEvent;
use App\Models\ObserveService;
use App\Models\Project;
use App\Support\Observe\OperationsEntityId;
use Illuminate\Support\Carbon;

/**
 * Sprint 21 — Incident Timeline.
 *
 * Reconstructs an incident timeline for an alert event purely from REAL timestamps already recorded
 * in QynSight: the alert lifecycle (triggered → opened → acknowledged → resolved), related
 * service-state transitions on the same host within the incident window, and related alerts that
 * fired in the same window. No timestamps are synthesized; if a stage never happened, it is omitted.
 */
class IncidentTimelineService
{
    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project, ObserveAlertEvent $event): array
    {
        $entries = [];

        $start = $event->triggered_at instanceof Carbon ? $event->triggered_at->copy() : null;
        $end = $event->resolved_at instanceof Carbon ? $event->resolved_at->copy() : now();
        $windowStart = $start?->copy()->subMinutes(15) ?? now()->subDay();

        // 1) Alert lifecycle (real timestamps only).
        $this->push($entries, $event->triggered_at, 'alert_triggered', sprintf('Alert "%s" triggered (%s).', $event->title, $event->severity), 'alert');
        if ($event->opened_at instanceof Carbon && (! $event->triggered_at instanceof Carbon || ! $event->opened_at->equalTo($event->triggered_at))) {
            $this->push($entries, $event->opened_at, 'alert_opened', 'Alert opened.', 'alert');
        }
        $this->push($entries, $event->acknowledged_at, 'alert_acknowledged', 'Alert acknowledged by an operator.', 'alert');
        $this->push($entries, $event->resolved_at, 'alert_resolved', 'Alert resolved.', 'recovery');

        // 2) Related service-state transitions on the same host within the window.
        if ($event->host_name !== null) {
            $stateChanges = ObserveService::query()
                ->where('workspace_id', $project->id)
                ->where('engine_key', 'native')
                ->where('host_name', $event->host_name)
                ->whereNotNull('last_state_change_at')
                ->where('last_state_change_at', '>=', $windowStart)
                ->where('last_state_change_at', '<=', $end)
                ->orderBy('last_state_change_at')
                ->get(['service_name', 'state', 'last_state_change_at', 'output']);

            foreach ($stateChanges as $change) {
                $this->push(
                    $entries,
                    $change->last_state_change_at,
                    'service_state_change',
                    sprintf('Service "%s" changed to %s.', $change->service_name, $change->state),
                    'service',
                );
            }
        }

        // 3) Related alerts on the same host within the window.
        $related = ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where('id', '!=', $event->id)
            ->when($event->host_name !== null, fn ($q) => $q->where('host_name', $event->host_name))
            ->where('triggered_at', '>=', $windowStart)
            ->where('triggered_at', '<=', $end)
            ->orderBy('triggered_at')
            ->limit(30)
            ->get();

        foreach ($related as $rel) {
            $this->push(
                $entries,
                $rel->triggered_at,
                'related_alert',
                sprintf('Related alert "%s" triggered (%s).', $rel->title, $rel->severity),
                'alert',
            );
        }

        // Sort chronologically.
        usort($entries, fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return [
            'incident_uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_INCIDENT, $project->id, (int) $event->id),
            'alert' => $this->evidence->alertSummary($project, $event),
            'started_at' => optional($event->triggered_at)->toIso8601String(),
            'resolved_at' => optional($event->resolved_at)->toIso8601String(),
            'duration_seconds' => ($start !== null && $event->resolved_at instanceof Carbon)
                ? $event->resolved_at->diffInSeconds($start)
                : null,
            'entries' => $entries,
            'entry_count' => count($entries),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function push(array &$entries, mixed $at, string $type, string $description, string $category): void
    {
        if (! $at instanceof Carbon) {
            return;
        }

        $entries[] = [
            'at' => $at->toIso8601String(),
            'type' => $type,
            'category' => $category,
            'description' => $description,
        ];
    }
}

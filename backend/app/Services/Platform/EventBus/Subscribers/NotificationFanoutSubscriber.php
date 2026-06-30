<?php

declare(strict_types=1);

namespace App\Services\Platform\EventBus\Subscribers;

use App\Contracts\Platform\EventSubscriber;
use App\Models\Project;
use App\Services\Notification\NotificationService;
use App\Services\Platform\EventBus\PlatformEvent;
use App\Services\Platform\EventBus\PlatformEventNames;

/**
 * Sprint 25 — example cross-module reaction WITHOUT module branching at the publisher.
 *
 * When an urgent domain event is published (alert created, workflow failed, incident opened) this
 * subscriber turns it into a deduplicated, routed notification through the shared QynNotify service. The
 * publisher (QynSight / QynRun / QynReact) never knows QynNotify exists — that is the point of the bus.
 * It is fully deterministic: severity and title come from the event payload (real data only).
 */
class NotificationFanoutSubscriber implements EventSubscriber
{
    /** Map of urgent events → notification severity. */
    private const URGENT = [
        PlatformEventNames::ALERT_CREATED => 'high',
        PlatformEventNames::WORKFLOW_FAILED => 'high',
        PlatformEventNames::INCIDENT_OPENED => 'critical',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function key(): string
    {
        return 'qynnotify.fanout';
    }

    /**
     * @return list<string>
     */
    public function subscribedTo(): array
    {
        return array_keys(self::URGENT);
    }

    public function handle(PlatformEvent $event): void
    {
        $project = Project::find($event->projectId);
        if ($project === null) {
            return;
        }

        $severity = (string) ($event->payload['severity'] ?? self::URGENT[$event->name] ?? 'medium');
        $title = (string) ($event->payload['title'] ?? $event->name);

        $this->notifications->ingest($project, null, [
            'type' => 'event',
            'severity' => $severity,
            'title' => $title,
            'body' => $event->payload['summary'] ?? null,
            'source' => (string) ($event->payload['source'] ?? 'platform_event_bus'),
            'correlation_id' => $event->correlationId,
            'metadata' => ['event' => $event->name, 'event_uuid' => $event->uuid],
        ]);
    }
}

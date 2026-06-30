<?php

declare(strict_types=1);

namespace App\Services\Platform\EventBus;

use App\Contracts\Platform\EventSubscriber;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\PlatformAuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 25 — the shared Platform Event Bus.
 *
 * Publish/Subscribe over deterministic domain events. Workspace-aware (every event carries a Project),
 * auditable (every publish is written to the shared audit trail), and async-ready (the dispatch seam is
 * isolated in {@see dispatch()} so a queue can be introduced without changing publishers or subscribers).
 *
 * There are NO direct module-to-module calls anywhere: publishers call {@see publish()} and the bus fans
 * out to subscribers registered via {@see subscribe()}. A failing subscriber never breaks publishing.
 */
class PlatformEventBus
{
    /** @var array<string, EventSubscriber> */
    private array $subscribers = [];

    /** @var list<array<string, mixed>> recent published events (bounded, in-process introspection only). */
    private array $recent = [];

    private const RECENT_LIMIT = 100;

    public function __construct(
        private readonly PlatformAuditLogger $audit,
    ) {}

    public function subscribe(EventSubscriber $subscriber): void
    {
        $this->subscribers[$subscriber->key()] = $subscriber;
    }

    /**
     * Publish a domain event. Unknown event names are rejected (typo-safe vocabulary).
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $name, Project $project, ?User $actor = null, array $payload = [], ?string $correlationId = null): PlatformEvent
    {
        if (! PlatformEventNames::isKnown($name)) {
            throw new \InvalidArgumentException("Unknown platform event: {$name}");
        }

        $event = PlatformEvent::make($name, $project, $actor, $payload, $correlationId);

        $this->audit->log($actor, $project, 'platform_event_published', [
            'event' => $event->name,
            'event_uuid' => $event->uuid,
            'correlation_id' => $event->correlationId,
        ]);

        $this->remember($event);
        $this->dispatch($event);

        return $event;
    }

    /**
     * Fan-out seam. Synchronous + defensive today; a queue worker can replace the body without touching
     * any publisher or subscriber (async-ready by design).
     */
    private function dispatch(PlatformEvent $event): void
    {
        foreach ($this->subscribersFor($event->name) as $subscriber) {
            try {
                $subscriber->handle($event);
            } catch (\Throwable $e) {
                // A subscriber must never break publishing.
                Log::warning('Platform event subscriber failed', [
                    'subscriber' => $subscriber->key(),
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<EventSubscriber>
     */
    public function subscribersFor(string $eventName): array
    {
        $matched = [];
        foreach ($this->subscribers as $subscriber) {
            $wants = $subscriber->subscribedTo();
            if (in_array('*', $wants, true) || in_array($eventName, $wants, true)) {
                $matched[] = $subscriber;
            }
        }

        return $matched;
    }

    /**
     * @return list<EventSubscriber>
     */
    public function subscribers(): array
    {
        return array_values($this->subscribers);
    }

    /**
     * Introspection for Platform Health: which events exist and who listens.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $byEvent = [];
        foreach (PlatformEventNames::all() as $name) {
            $byEvent[$name] = array_map(static fn (EventSubscriber $s): string => $s->key(), $this->subscribersFor($name));
        }

        return [
            'event_count' => count(PlatformEventNames::all()),
            'subscriber_count' => count($this->subscribers),
            'subscribers' => array_map(static fn (EventSubscriber $s): array => [
                'key' => $s->key(),
                'subscribed_to' => $s->subscribedTo(),
            ], array_values($this->subscribers)),
            'events' => $byEvent,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 25): array
    {
        return array_slice($this->recent, 0, max(1, min($limit, self::RECENT_LIMIT)));
    }

    private function remember(PlatformEvent $event): void
    {
        array_unshift($this->recent, $event->toArray());
        if (count($this->recent) > self::RECENT_LIMIT) {
            $this->recent = array_slice($this->recent, 0, self::RECENT_LIMIT);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Contracts\Platform;

use App\Services\Platform\EventBus\PlatformEvent;

/**
 * Sprint 25 — a Platform Event Bus subscriber.
 *
 * Modules react to domain events through handlers that implement this contract and self-register with the
 * bus. This is how cross-module reactions happen WITHOUT direct module-to-module calls or module
 * branching: the publisher knows nothing about its subscribers.
 */
interface EventSubscriber
{
    /**
     * Stable subscriber key (for introspection / health).
     */
    public function key(): string;

    /**
     * Event names this subscriber wants. Return `['*']` to receive every event.
     *
     * @return list<string>
     */
    public function subscribedTo(): array;

    /**
     * Handle a published event. MUST be defensive — a failing subscriber must never break publishing.
     */
    public function handle(PlatformEvent $event): void;
}

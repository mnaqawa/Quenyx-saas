<?php

declare(strict_types=1);

namespace App\Services\Platform\EventBus;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * GA REMEDIATION: shared helper for domain services to publish real Platform Event
 * Bus events without taking a hard constructor dependency on the bus (keeps service
 * constructors stable and test-instantiation simple). Publishing is best-effort:
 * a missing/failed bus must never break the originating business operation.
 *
 * @see \App\Services\Platform\EventBus\PlatformEventBus
 */
trait PublishesPlatformEvents
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function publishPlatformEvent(
        string $name,
        Project $project,
        ?User $actor = null,
        array $payload = [],
        ?string $correlationId = null,
    ): void {
        try {
            /** @var PlatformEventBus $bus */
            $bus = app(PlatformEventBus::class);
            $bus->publish($name, $project, $actor, $payload, $correlationId);
        } catch (\Throwable $e) {
            Log::warning('Platform event publish skipped', [
                'event' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

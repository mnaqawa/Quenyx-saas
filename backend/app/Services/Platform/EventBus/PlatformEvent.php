<?php

declare(strict_types=1);

namespace App\Services\Platform\EventBus;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Sprint 25 — an immutable platform domain event.
 *
 * Workspace-aware (carries the publishing Project) and actor-aware (optional User). The payload is the
 * deterministic, real data describing what happened — never fabricated. Events are addressed by UUID and
 * may be correlated via `correlationId`.
 */
final class PlatformEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly int $projectId,
        public readonly string $workspaceUuid,
        public readonly ?int $actorId,
        public readonly array $payload,
        public readonly string $occurredAt,
        public readonly ?string $correlationId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(
        string $name,
        Project $project,
        ?User $actor = null,
        array $payload = [],
        ?string $correlationId = null,
    ): self {
        return new self(
            uuid: (string) Str::uuid(),
            name: $name,
            projectId: $project->id,
            workspaceUuid: (string) $project->uuid,
            actorId: $actor?->id,
            payload: $payload,
            occurredAt: now()->toIso8601String(),
            correlationId: $correlationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'workspace_uuid' => $this->workspaceUuid,
            'actor_id' => $this->actorId,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}

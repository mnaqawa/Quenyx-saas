<?php

declare(strict_types=1);

namespace App\Support\Observe;

use Ramsey\Uuid\Uuid;

/**
 * Sprint 21 — Operations Intelligence.
 *
 * QynSight ("Observe") entities (hosts, services, alert events) use numeric primary keys, but the
 * Operations Intelligence API surface is UUID-only (matching the platform standard). Rather than
 * mutating the live monitoring schema, we derive a STABLE, deterministic UUIDv5 from
 * (entity-type, workspace_id, numeric-id). The same inputs always yield the same UUID, so the UI can
 * reference an entity by UUID and the backend can resolve it back by scanning the (small,
 * workspace-scoped) candidate set — no schema change, fully backward compatible.
 */
final class OperationsEntityId
{
    public const TYPE_HOST = 'host';

    public const TYPE_SERVICE = 'service';

    public const TYPE_ALERT = 'alert';

    public const TYPE_INCIDENT = 'incident';

    /**
     * Deterministic UUIDv5 for a workspace-scoped Observe entity.
     */
    public static function for(string $type, int $workspaceId, int $id): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "quenyx://qynsight/{$type}/{$workspaceId}/{$id}")->toString();
    }
}

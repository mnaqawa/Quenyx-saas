<?php

declare(strict_types=1);

namespace App\Support\Asset;

use Ramsey\Uuid\Uuid;

/**
 * Sprint 22 — QynAsset Intelligence entity ids.
 *
 * QynAsset has no asset table of its own; an "asset" is a DISCOVERED host (an `observe_targets_hosts`
 * row, enriched by its linked agent + latest inventory). Those rows use numeric primary keys, but the
 * QynAsset API surface is UUID-only. We derive a STABLE, deterministic UUIDv5 from
 * (entity-type, workspace_id, numeric-id) — the same approach Operations Intelligence uses — so the
 * UI references an asset by UUID and the backend resolves it back by scanning the small,
 * workspace-scoped candidate set. No schema change.
 */
final class AssetEntityId
{
    public const TYPE_ASSET = 'asset';

    public static function for(string $type, int $workspaceId, int $id): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "quenyx://qynasset/{$type}/{$workspaceId}/{$id}")->toString();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Support\Asset\AssetEntityId;

/**
 * Sprint 22 — resolves a UUID-only QynAsset identifier back to the underlying host row, always scoped
 * to the workspace. Matches the deterministic UUIDv5 produced by {@see AssetEntityId} against the
 * workspace's (small) host set — no schema change.
 */
class AssetEntityResolver
{
    public function resolveAsset(Project $project, string $uuid): ?ObserveTargetHost
    {
        return ObserveTargetHost::query()
            ->where('workspace_id', $project->id)
            ->get()
            ->first(fn (ObserveTargetHost $host): bool => AssetEntityId::for(AssetEntityId::TYPE_ASSET, $project->id, (int) $host->id) === $uuid);
    }
}

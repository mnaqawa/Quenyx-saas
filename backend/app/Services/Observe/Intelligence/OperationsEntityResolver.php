<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveAlertEvent;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Support\Observe\OperationsEntityId;

/**
 * Sprint 21 — resolves a UUID-only API identifier back to the underlying numeric Observe entity,
 * always scoped to the workspace. Resolution matches the deterministic UUIDv5 produced by
 * {@see OperationsEntityId} against the workspace's candidate rows (host/service/alert counts per
 * workspace are small, so a scoped scan is cheap and avoids any monitoring schema change).
 */
class OperationsEntityResolver
{
    public function resolveHost(Project $project, string $uuid): ?ObserveTargetHost
    {
        return ObserveTargetHost::query()
            ->where('workspace_id', $project->id)
            ->get()
            ->first(fn (ObserveTargetHost $host): bool => OperationsEntityId::for(OperationsEntityId::TYPE_HOST, $project->id, (int) $host->id) === $uuid);
    }

    public function resolveService(Project $project, string $uuid): ?ObserveService
    {
        return ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->get()
            ->first(fn (ObserveService $service): bool => OperationsEntityId::for(OperationsEntityId::TYPE_SERVICE, $project->id, (int) $service->id) === $uuid);
    }

    /**
     * Resolve an alert event. Bounded to the metrics retention window plus all currently-open events
     * so resolution stays cheap while always covering anything the UI can surface.
     */
    public function resolveAlert(Project $project, string $uuid): ?ObserveAlertEvent
    {
        $retentionDays = (int) config('observe.metrics_retention_days', 31);

        return ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where(function ($query) use ($retentionDays): void {
                $query->where('triggered_at', '>=', now()->subDays(max($retentionDays, 31)))
                    ->orWhereIn('status', (array) config('alerts.open_statuses', ['open', 'active', 'acknowledged']));
            })
            ->orderByDesc('triggered_at')
            ->get()
            ->first(fn (ObserveAlertEvent $event): bool => OperationsEntityId::for(OperationsEntityId::TYPE_ALERT, $project->id, (int) $event->id) === $uuid);
    }
}

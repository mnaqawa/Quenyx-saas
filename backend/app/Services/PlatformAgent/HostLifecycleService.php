<?php

namespace App\Services\PlatformAgent;

use App\Constants\HostLifecycleStatus;
use App\Models\Agent;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\EventBus\PlatformEventNames;
use App\Services\Platform\EventBus\PublishesPlatformEvents;
use App\Services\Platform\PlatformAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HostLifecycleService
{
    use PublishesPlatformEvents;

    public function __construct(
        private PlatformAuditLogger $audit
    ) {
    }

    public function isMonitoringAllowed(ObserveTargetHost $host): bool
    {
        $status = $host->lifecycle_status ?? HostLifecycleStatus::ACTIVE;

        return ! in_array($status, HostLifecycleStatus::monitoringBlocked(), true)
            && $host->deleted_at === null
            && ($host->enabled ?? true);
    }

    public function disableMonitoring(Project $project, ObserveTargetHost $host, ?User $user, ?string $reason = null): ObserveTargetHost
    {
        return $this->transition($project, $host, $user, HostLifecycleStatus::MONITORING_DISABLED, $reason ?? 'Monitoring disabled by administrator.', disableServices: true, event: PlatformEventNames::HOST_MONITORING_DISABLED);
    }

    public function suspend(Project $project, ObserveTargetHost $host, ?User $user, ?string $reason = null): ObserveTargetHost
    {
        return $this->transition($project, $host, $user, HostLifecycleStatus::SUSPENDED, $reason ?? 'Host suspended.', disableServices: true);
    }

    public function archive(Project $project, ObserveTargetHost $host, ?User $user, ?string $reason = null): ObserveTargetHost
    {
        return $this->transition($project, $host, $user, HostLifecycleStatus::ARCHIVED, $reason ?? 'Host archived.', disableServices: true);
    }

    public function markAgentRemoved(Project $project, ObserveTargetHost $host, ?User $user, ?string $reason = null): ObserveTargetHost
    {
        return $this->transition(
            $project,
            $host,
            $user,
            HostLifecycleStatus::AGENT_REMOVED,
            $reason ?? 'Monitoring disabled because the platform agent was removed.',
            disableServices: true,
            clearAgentLink: true,
            event: PlatformEventNames::HOST_MONITORING_DISABLED
        );
    }

    public function restore(Project $project, ObserveTargetHost $host, ?User $user): ObserveTargetHost
    {
        if (! in_array($host->lifecycle_status, [
            HostLifecycleStatus::SUSPENDED,
            HostLifecycleStatus::ARCHIVED,
            HostLifecycleStatus::MONITORING_DISABLED,
            HostLifecycleStatus::AGENT_REMOVED,
        ], true)) {
            return $host;
        }

        $host->update([
            'lifecycle_status' => HostLifecycleStatus::ACTIVE,
            'lifecycle_reason' => null,
            'lifecycle_changed_at' => now(),
            'enabled' => true,
            'deleted_at' => null,
        ]);

        ObserveTargetService::where('host_id', $host->id)->update(['enabled' => true]);
        $this->setObserveServicesState($host, 'pending', 'Monitoring restored — awaiting next check.');

        $this->audit->log($user, $project, 'host.lifecycle.restored', [
            'host_id' => $host->id,
            'host_uuid' => $host->uuid,
            'host_name' => $host->name,
        ]);

        return $host->fresh();
    }

    /**
     * Soft-delete host; block if metric history exists unless force.
     */
    public function delete(Project $project, ObserveTargetHost $host, ?User $user, bool $force = false): void
    {
        if (! $force && $this->hasDependentHistory($host)) {
            throw new \RuntimeException('Cannot permanently delete host while check history exists. Archive the host instead, or use force delete.');
        }

        $this->transition($project, $host, $user, HostLifecycleStatus::DELETED, 'Host deleted.', disableServices: true);

        $host->update(['deleted_at' => now()]);

        $this->audit->log($user, $project, 'host.lifecycle.deleted', [
            'host_id' => $host->id,
            'host_uuid' => $host->uuid,
            'host_name' => $host->name,
            'force' => $force,
        ]);
    }

    public function updateHealthFromTelemetry(ObserveTargetHost $host, string $worstState): void
    {
        if (! $this->isMonitoringAllowed($host)) {
            return;
        }

        $map = [
            'ok' => HostLifecycleStatus::ONLINE,
            'warning' => HostLifecycleStatus::WARNING,
            'critical' => HostLifecycleStatus::CRITICAL,
            'unknown' => HostLifecycleStatus::PENDING,
            'pending' => HostLifecycleStatus::PENDING,
        ];
        $next = $map[$worstState] ?? HostLifecycleStatus::PENDING;

        if ($host->lifecycle_status !== $next) {
            $host->update([
                'lifecycle_status' => $next,
                'lifecycle_changed_at' => now(),
            ]);
        }
    }

    private function transition(
        Project $project,
        ObserveTargetHost $host,
        ?User $user,
        string $status,
        string $reason,
        bool $disableServices = false,
        bool $clearAgentLink = false,
        ?string $event = null
    ): ObserveTargetHost {
        $updates = [
            'lifecycle_status' => $status,
            'lifecycle_reason' => $reason,
            'lifecycle_changed_at' => now(),
        ];

        if (in_array($status, HostLifecycleStatus::monitoringBlocked(), true)) {
            $updates['enabled'] = false;
        }

        if ($clearAgentLink) {
            $updates['agent_id'] = null;
        }

        if (empty($host->uuid)) {
            $updates['uuid'] = (string) Str::uuid();
        }

        $host->update($updates);

        if ($disableServices) {
            ObserveTargetService::where('host_id', $host->id)->update(['enabled' => false]);
            $this->setObserveServicesState($host, 'pending', $reason);
        }

        $this->audit->log($user, $project, 'host.lifecycle.'.$status, [
            'host_id' => $host->id,
            'host_uuid' => $host->uuid,
            'host_name' => $host->name,
            'reason' => $reason,
        ]);

        if ($event !== null) {
            $this->publishPlatformEvent($event, $project, $user, [
                'host_id' => $host->id,
                'host_uuid' => $host->uuid,
                'host_name' => $host->name,
                'lifecycle_status' => $status,
                'reason' => $reason,
            ]);
        }

        return $host->fresh();
    }

    private function setObserveServicesState(ObserveTargetHost $host, string $state, string $output): void
    {
        if (! Schema::hasTable('observe_services')) {
            return;
        }

        $prefix = 'ws'.$host->workspace_id.'-';
        $hostName = $prefix.$host->name;

        ObserveService::query()
            ->where('workspace_id', $host->workspace_id)
            ->where('host_name', $hostName)
            ->update([
                'state' => $state,
                'output' => $output,
                'plugin_output' => $output,
                'last_check_at' => now(),
            ]);
    }

    private function hasDependentHistory(ObserveTargetHost $host): bool
    {
        if (! Schema::hasTable('observe_metrics_history')) {
            return false;
        }

        $prefix = 'ws'.$host->workspace_id.'-';

        return DB::table('observe_metrics_history')
            ->where('workspace_id', $host->workspace_id)
            ->where('host_name', 'like', $prefix.$host->name.'%')
            ->exists();
    }
}

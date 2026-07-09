<?php

namespace App\Services;

use App\Constants\AgentConstants;
use App\Models\MonitoringProfile;
use App\Models\MonitoringProfileCheck;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use Illuminate\Support\Facades\Schema;

/**
 * Attaches monitoring checks to hosts (idempotent).
 * Agent-enrolled hosts receive push-based Platform Agent telemetry checks — never SSH/pull plugins.
 */
class DefaultMonitoringProfileService
{
    /**
     * @return array{attached: int, skipped: int}
     */
    public function attachToHost(ObserveTargetHost $host, int $workspaceId): array
    {
        if ($host->agent_id || $host->source === 'agent') {
            return $this->attachAgentTelemetryChecks($host, $workspaceId);
        }

        return $this->attachPullChecks($host, $workspaceId);
    }

    /**
     * Platform Agent hosts: telemetry-based checks only. No SSH, no pull plugins.
     *
     * @return array{attached: int, skipped: int}
     */
    public function attachAgentTelemetryChecks(ObserveTargetHost $host, int $workspaceId): array
    {
        $checks = [
            ['service_key' => 'cpu', 'service_name' => 'cpu', 'check_args' => ['warn_pct' => 80, 'crit_pct' => 90]],
            ['service_key' => 'memory', 'service_name' => 'memory', 'check_args' => ['warn_pct' => 80, 'crit_pct' => 90]],
            ['service_key' => 'disk', 'service_name' => 'disk', 'check_args' => ['mount' => '/', 'warn_pct' => 20, 'crit_pct' => 10]],
            ['service_key' => 'load', 'service_name' => 'load', 'check_args' => []],
        ];

        $attached = 0;
        $skipped = 0;

        foreach ($checks as $check) {
            $exists = ObserveTargetService::query()
                ->where('host_id', $host->id)
                ->where(function ($q) use ($check) {
                    $q->where('name', $check['service_name']);
                    if (Schema::hasColumn('observe_targets_services', 'service_key')) {
                        $q->orWhere('service_key', $check['service_key']);
                    }
                })
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $data = [
                'workspace_id' => $workspaceId,
                'check_command' => 'platform_agent_telemetry',
                'check_args' => $check['check_args'],
                'enabled' => true,
            ];

            if (Schema::hasColumn('observe_targets_services', 'service_key')) {
                $data['service_key'] = $check['service_key'];
            }
            if (Schema::hasColumn('observe_targets_services', 'check_source')) {
                $data['check_source'] = AgentConstants::CHECK_SOURCE_PLATFORM_AGENT;
            }

            ObserveTargetService::create(array_merge($data, [
                'host_id' => $host->id,
                'name' => $check['service_name'],
            ]));
            $attached++;
        }

        return ['attached' => $attached, 'skipped' => $skipped];
    }

    /**
     * Manual hosts: standard pull-based profile (may include plugins).
     *
     * @return array{attached: int, skipped: int}
     */
    private function attachPullChecks(ObserveTargetHost $host, int $workspaceId): array
    {
        if (! Schema::hasTable('monitoring_profiles') || ! Schema::hasTable('monitoring_profile_checks')) {
            return ['attached' => 0, 'skipped' => 0];
        }

        $profile = MonitoringProfile::query()
            ->where(function ($q) use ($workspaceId) {
                $q->where('workspace_id', $workspaceId)->where('is_default', true);
            })
            ->orWhere(function ($q) {
                $q->whereNull('workspace_id')->where('profile_key', 'default_infrastructure');
            })
            ->orderByRaw('workspace_id IS NULL ASC')
            ->first();

        if (! $profile) {
            return ['attached' => 0, 'skipped' => 0];
        }

        $attached = 0;
        $skipped = 0;

        /** @var MonitoringProfileCheck $check */
        foreach ($profile->checks()->where('enabled', true)->get() as $check) {
            if ($check->service_key === 'ping') {
                if (! in_array($host->check_command, ['check-host-alive', 'check_ping'], true)) {
                    $host->update(['check_command' => 'check_ping']);
                }
                $skipped++;

                continue;
            }

            $exists = ObserveTargetService::query()
                ->where('host_id', $host->id)
                ->where(function ($q) use ($check) {
                    $q->where('name', $check->service_name);
                    if (Schema::hasColumn('observe_targets_services', 'service_key')) {
                        $q->orWhere('service_key', $check->service_key);
                    }
                })
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $definition = ObserveServiceDefinition::query()
                ->where('service_key', $check->service_key)
                ->where('status', 'active')
                ->first();

            if (! $definition) {
                $skipped++;

                continue;
            }

            $data = [
                'workspace_id' => $workspaceId,
                'check_command' => $definition->check_command,
                'check_args' => $check->check_args ?? [],
                'enabled' => true,
            ];
            if (Schema::hasColumn('observe_targets_services', 'service_key')) {
                $data['service_key'] = $check->service_key;
            }
            if (Schema::hasColumn('observe_targets_services', 'check_source')) {
                $data['check_source'] = AgentConstants::CHECK_SOURCE_PULL;
            }

            ObserveTargetService::create(array_merge($data, [
                'host_id' => $host->id,
                'name' => $check->service_name,
            ]));
            $attached++;
        }

        return ['attached' => $attached, 'skipped' => $skipped];
    }
}

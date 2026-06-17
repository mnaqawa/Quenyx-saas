<?php

namespace App\Services;

use App\Models\MonitoringProfile;
use App\Models\MonitoringProfileCheck;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use Illuminate\Support\Facades\Schema;

/**
 * Attaches default infrastructure monitoring checks to hosts (idempotent).
 */
class DefaultMonitoringProfileService
{
    /**
     * @return array{attached: int, skipped: int}
     */
    public function attachToHost(ObserveTargetHost $host, int $workspaceId): array
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

            ObserveTargetService::create(array_merge($data, [
                'host_id' => $host->id,
                'name' => $check->service_name,
            ]));
            $attached++;
        }

        return ['attached' => $attached, 'skipped' => $skipped];
    }
}

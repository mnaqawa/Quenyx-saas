<?php

namespace App\Services;

use App\Models\MonitoringProfile;
use App\Models\MonitoringProfileCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MonitoringProfileService
{
    /**
     * @return array{profile: array<string, mixed>, checks: array<int, array<string, mixed>>}
     */
    public function getWorkspaceProfile(int $workspaceId): array
    {
        if (! Schema::hasTable('monitoring_profiles')) {
            return ['profile' => [], 'checks' => []];
        }

        $profile = $this->getOrCreateWorkspaceProfile($workspaceId);

        return [
            'profile' => [
                'id' => $profile->id,
                'workspace_id' => $profile->workspace_id,
                'profile_key' => $profile->profile_key,
                'name' => $profile->name,
                'is_default' => $profile->is_default,
            ],
            'checks' => $profile->checks->map(fn (MonitoringProfileCheck $c) => [
                'id' => $c->id,
                'service_key' => $c->service_key,
                'service_name' => $c->service_name,
                'check_args' => $c->check_args ?? [],
                'enabled' => $c->enabled,
                'sort_order' => $c->sort_order,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, array{service_key: string, check_args?: array, enabled?: bool}>  $checks
     * @return array{profile: array<string, mixed>, checks: array<int, array<string, mixed>>}
     */
    public function updateWorkspaceProfile(int $workspaceId, array $checks): array
    {
        $profile = $this->getOrCreateWorkspaceProfile($workspaceId);

        foreach ($checks as $checkData) {
            $serviceKey = $checkData['service_key'] ?? null;
            if (! $serviceKey) {
                continue;
            }

            $check = $profile->checks()->where('service_key', $serviceKey)->first();
            if (! $check) {
                continue;
            }

            $args = $checkData['check_args'] ?? $check->check_args ?? [];
            $this->validateThresholds($serviceKey, $args);

            $check->update([
                'check_args' => $args,
                'enabled' => $checkData['enabled'] ?? $check->enabled,
            ]);
        }

        return $this->getWorkspaceProfile($workspaceId);
    }

    public function getOrCreateWorkspaceProfile(int $workspaceId): MonitoringProfile
    {
        $existing = MonitoringProfile::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->first();

        if ($existing) {
            return $existing->load('checks');
        }

        return DB::transaction(function () use ($workspaceId) {
            $global = MonitoringProfile::query()
                ->whereNull('workspace_id')
                ->where('profile_key', 'default_infrastructure')
                ->with('checks')
                ->first();

            $profile = MonitoringProfile::create([
                'workspace_id' => $workspaceId,
                'profile_key' => 'workspace_default',
                'name' => 'Workspace Default Monitoring',
                'is_default' => true,
            ]);

            if ($global) {
                foreach ($global->checks as $globalCheck) {
                    MonitoringProfileCheck::create([
                        'profile_id' => $profile->id,
                        'service_key' => $globalCheck->service_key,
                        'service_name' => $globalCheck->service_name,
                        'check_args' => $globalCheck->check_args,
                        'enabled' => $globalCheck->enabled,
                        'sort_order' => $globalCheck->sort_order,
                    ]);
                }
            }

            return $profile->load('checks');
        });
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function validateThresholds(string $serviceKey, array $args): void
    {
        if (in_array($serviceKey, ['cpu', 'memory'], true)) {
            $warn = isset($args['warn_pct']) ? (float) $args['warn_pct'] : null;
            $crit = isset($args['crit_pct']) ? (float) $args['crit_pct'] : null;
            if ($warn !== null && $crit !== null && $warn >= $crit) {
                throw ValidationException::withMessages([
                    'check_args' => ['Warning threshold must be less than critical threshold.'],
                ]);
            }
        }

        if ($serviceKey === 'disk') {
            $warn = isset($args['warn_pct']) ? (float) $args['warn_pct'] : null;
            $crit = isset($args['crit_pct']) ? (float) $args['crit_pct'] : null;
            if ($warn !== null && $crit !== null && $warn <= $crit) {
                throw ValidationException::withMessages([
                    'check_args' => ['Disk warning free-space threshold must be greater than critical.'],
                ]);
            }
        }
    }
}

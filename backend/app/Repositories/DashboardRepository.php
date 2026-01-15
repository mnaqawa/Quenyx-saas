<?php

namespace App\Repositories;

use App\Module;
use App\ModuleSubscription;

class DashboardRepository
{
    private const PERFORMANCE_LABELS = ['CPU', 'Memory', 'Network'];

    public function getPlatformHealth(): string
    {
        // For simplicity, return 'ok' as platform health
        return 'ok';
    }

    public function getModulesWithSubscriptions(): array
    {
        return Module::with('subscriptions')->get()->map(function ($module) {
            return [
                'id' => $module->id,
                'name' => $module->name,
                'description' => $module->description,
                'status' => $module->status,
                'subscription_state' => $module->subscriptions->first()?->subscription_state ?? 'inactive',
            ];
        })->toArray();
    }

    public function getPerformanceSeries(array $modules): array
    {
        $activeRatio = $this->getActiveRatio($modules);
        $base = $activeRatio > 0 ? $activeRatio * 100 : 55;

        $series = [
            ['label' => 'CPU', 'values' => [$base - 15, $base - 4, $base + 10, $base + 16, $base + 6, $base - 8]],
            ['label' => 'Memory', 'values' => [$base - 22, $base - 10, $base + 2, $base + 9, $base + 1, $base - 12]],
            ['label' => 'Network', 'values' => [$base - 30, $base - 18, $base - 6, $base + 2, $base - 3, $base - 20]],
        ];

        return array_map(function ($entry) {
            $entry['values'] = array_map(function ($value) {
                return max(10, min(90, (int) round($value)));
            }, $entry['values']);

            return $entry;
        }, $series);
    }

    public function getWeeklyUptime(array $modules): array
    {
        $activeRatio = $this->getActiveRatio($modules);
        $base = 98.8 + ($activeRatio * 1.1);

        return [
            round($base - 0.4, 1),
            round($base - 0.6, 1),
            round($base - 0.3, 1),
            round($base + 0.1, 1),
            round($base - 0.7, 1),
            round($base - 0.2, 1),
            round($base, 1),
        ];
    }

    public function getAlertsByModule(array $modules): array
    {
        if (count($modules) === 0) {
            return [
                ['label' => 'QynSight', 'primary' => 6, 'secondary' => 2],
                ['label' => 'QynReact', 'primary' => 4, 'secondary' => 1],
                ['label' => 'QynShield', 'primary' => 5, 'secondary' => 2],
                ['label' => 'QynNotify', 'primary' => 6, 'secondary' => 2],
                ['label' => 'Others', 'primary' => 5, 'secondary' => 1],
            ];
        }

        return collect($modules)->take(5)->map(function ($module) {
            $primary = match ($module['status'] ?? 'inactive') {
                'active' => 7,
                'maintenance' => 5,
                default => 3,
            };
            $secondary = match ($module['subscription_state'] ?? 'inactive') {
                'active' => 2,
                'trial' => 1,
                'expired' => 3,
                default => 2,
            };

            return [
                'label' => $module['name'] ?? 'Module',
                'primary' => $primary,
                'secondary' => $secondary,
            ];
        })->values()->toArray();
    }

    private function getActiveRatio(array $modules): float
    {
        $total = count($modules);
        if ($total === 0) {
            return 0.0;
        }

        $active = collect($modules)->where('status', 'active')->count();
        return $active / $total;
    }
}
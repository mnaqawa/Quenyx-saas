<?php

namespace App\Repositories;

use App\Module;
use App\ModuleSubscription;

class DashboardRepository
{
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
}
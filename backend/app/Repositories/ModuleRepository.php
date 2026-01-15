<?php

namespace App\Repositories;

use App\Module;

class ModuleRepository
{
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

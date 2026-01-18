<?php

namespace App\Repositories;

use App\Models\Module;

class ModuleRepository
{
    public function getModulesWithSubscriptions(): array
    {
        return Module::with('subscriptions')->get()->map(function ($module) {
            return [
                'id' => $module->id,
                'key' => $module->key,
                'name' => $module->name,
                'description' => $module->description,
                'status' => $module->status,
                'subscription_state' => $module->subscriptions->first()?->subscription_state ?? 'inactive',
            ];
        })->toArray();
    }

    /**
     * Get all modules (catalog)
     */
    public function getAllModules(): array
    {
        return Module::query()
            ->orderBy('name')
            ->get()
            ->map(function ($module) {
                return [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'status' => $module->status,
                ];
            })
            ->toArray();
    }
}

<?php

namespace App\Repositories;

use App\Models\Module;

class ModuleRepository
{
    public function getModulesWithSubscriptions(): array
    {
        return Module::query()
            ->where(function ($query) {
                $query->where('key', 'like', 'shield%')
                    ->orWhere('name', 'like', 'Shield%');
            })
            ->with('subscriptions')
            ->get()
            ->unique('key') // Ensure no duplicates
            ->map(function ($module) {
                return [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'status' => $module->status,
                    'subscription_state' => $module->subscriptions->first()?->subscription_state ?? 'inactive',
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get all modules (catalog)
     * Defense in depth: Only return Shield modules
     */
    public function getAllModules(): array
    {
        return Module::query()
            ->where(function ($query) {
                $query->where('key', 'like', 'shield%')
                    ->orWhere('name', 'like', 'Shield%');
            })
            ->orderBy('name')
            ->get()
            ->unique('key') // Ensure no duplicates
            ->map(function ($module) {
                return [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'status' => $module->status,
                ];
            })
            ->values()
            ->toArray();
    }
}

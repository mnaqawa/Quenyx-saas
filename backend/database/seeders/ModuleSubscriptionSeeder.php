<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleSubscription;
use Illuminate\Database\Seeder;

class ModuleSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $keys = [
            'qyncore', 'qynsight', 'qynasset', 'qynreact', 'qynshield', 'qynnotify',
            'qynva', 'qynknow', 'qynrun', 'qynbalance', 'qynsupport',
        ];
        $stateByKey = [
            'qyncore' => 'active',
            'qynsight' => 'trial',
            'qynasset' => 'inactive',
            'qynreact' => 'active',
            'qynshield' => 'active',
            'qynnotify' => 'trial',
            'qynva' => 'inactive',
            'qynknow' => 'active',
            'qynrun' => 'active',
            'qynbalance' => 'active',
            'qynsupport' => 'active',
        ];

        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if ($module) {
                ModuleSubscription::updateOrCreate(
                    ['module_id' => $module->id],
                    ['subscription_state' => $stateByKey[$key] ?? 'active']
                );
            }
        }
    }
}

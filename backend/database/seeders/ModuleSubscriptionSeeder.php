<?php

namespace Database\Seeders;

use App\Module;
use App\ModuleSubscription;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ModuleSubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $firewall = Module::where('name', 'Firewall')->first();
        $antivirus = Module::where('name', 'Antivirus')->first();
        $intrusion = Module::where('name', 'Intrusion Detection')->first();

        if ($firewall) {
            ModuleSubscription::create([
                'module_id' => $firewall->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($antivirus) {
            ModuleSubscription::create([
                'module_id' => $antivirus->id,
                'subscription_state' => 'trial',
            ]);
        }

        if ($intrusion) {
            ModuleSubscription::create([
                'module_id' => $intrusion->id,
                'subscription_state' => 'inactive',
            ]);
        }
    }
}

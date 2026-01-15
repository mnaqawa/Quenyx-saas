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
        $core = Module::where('name', 'ShieldCore')->first();
        $observe = Module::where('name', 'ShieldObserve')->first();
        $inventory = Module::where('name', 'ShieldInventory')->first();
        $respond = Module::where('name', 'ShieldRespond')->first();
        $secure = Module::where('name', 'ShieldSecure')->first();
        $notify = Module::where('name', 'ShieldNotify')->first();
        $voice = Module::where('name', 'ShieldVoice')->first();
        $knowledge = Module::where('name', 'ShieldKnowledge')->first();
        $automate = Module::where('name', 'ShieldAutomate')->first();
        $balance = Module::where('name', 'ShieldBalance')->first();
        $desk = Module::where('name', 'ShieldDesk')->first();

        if ($core) {
            ModuleSubscription::create([
                'module_id' => $core->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($observe) {
            ModuleSubscription::create([
                'module_id' => $observe->id,
                'subscription_state' => 'trial',
            ]);
        }

        if ($inventory) {
            ModuleSubscription::create([
                'module_id' => $inventory->id,
                'subscription_state' => 'inactive',
            ]);
        }

        if ($respond) {
            ModuleSubscription::create([
                'module_id' => $respond->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($secure) {
            ModuleSubscription::create([
                'module_id' => $secure->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($notify) {
            ModuleSubscription::create([
                'module_id' => $notify->id,
                'subscription_state' => 'trial',
            ]);
        }

        if ($voice) {
            ModuleSubscription::create([
                'module_id' => $voice->id,
                'subscription_state' => 'inactive',
            ]);
        }

        if ($knowledge) {
            ModuleSubscription::create([
                'module_id' => $knowledge->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($automate) {
            ModuleSubscription::create([
                'module_id' => $automate->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($balance) {
            ModuleSubscription::create([
                'module_id' => $balance->id,
                'subscription_state' => 'active',
            ]);
        }

        if ($desk) {
            ModuleSubscription::create([
                'module_id' => $desk->id,
                'subscription_state' => 'active',
            ]);
        }
    }
}

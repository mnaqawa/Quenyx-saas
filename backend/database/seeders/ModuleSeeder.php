<?php

namespace Database\Seeders;

use App\Module;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Module::create([
            'name' => 'ShieldCore',
            'description' => 'Central configuration and governance hub.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldObserve',
            'description' => 'Monitoring and visibility across infrastructure.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldInventory',
            'description' => 'Asset and CMDB management for systems.',
            'status' => 'maintenance',
        ]);

        Module::create([
            'name' => 'ShieldRespond',
            'description' => 'Incident response and orchestration workflows.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldSecure',
            'description' => 'Security operations and threat management.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldNotify',
            'description' => 'Alerting and communications hub.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldVoice',
            'description' => 'AI voice and IVR agent operations.',
            'status' => 'inactive',
        ]);

        Module::create([
            'name' => 'ShieldKnowledge',
            'description' => 'Documentation and runbook management.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldAutomate',
            'description' => 'Workflow automation and orchestration.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldBalance',
            'description' => 'Load balancing and traffic control.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldDesk',
            'description' => 'Ticketing and support operations.',
            'status' => 'active',
        ]);
    }
}

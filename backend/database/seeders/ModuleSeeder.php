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
            'description' => 'Central configuration and governance hub for platform control and policy management.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldObserve',
            'description' => 'Real-time infrastructure monitoring and performance insights across your environment.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldInventory',
            'description' => 'Comprehensive asset discovery, inventory management, and automated health tracking.',
            'status' => 'maintenance',
        ]);

        Module::create([
            'name' => 'ShieldRespond',
            'description' => 'Automated incident response and orchestration for rapid recovery and resolution.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldSecure',
            'description' => 'Security operations center for threat monitoring, vulnerability scanning, and posture defense.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldNotify',
            'description' => 'Alert and notification management across email, SMS, and in-app channels.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldVoice',
            'description' => 'AI voice and IVR operations for automated customer support and service analytics.',
            'status' => 'inactive',
        ]);

        Module::create([
            'name' => 'ShieldKnowledge',
            'description' => 'Knowledge management for documentation, playbooks, and operational procedures.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldAutomate',
            'description' => 'Workflow automation and process orchestration across systems and teams.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldBalance',
            'description' => 'Load balancing and traffic management for optimal resource distribution.',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'ShieldDesk',
            'description' => 'Help desk operations for ticketing, SLA compliance, and customer satisfaction.',
            'status' => 'active',
        ]);
    }
}

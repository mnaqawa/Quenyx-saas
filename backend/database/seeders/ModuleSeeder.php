<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Use updateOrCreate to handle existing modules
        Module::updateOrCreate(
            ['key' => 'shieldcore'],
            [
                'name' => 'ShieldCore',
                'description' => 'Central configuration and governance hub for platform control and policy management.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldobserve'],
            [
                'name' => 'ShieldObserve',
                'description' => 'Real-time infrastructure monitoring and performance insights across your environment.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldinventory'],
            [
                'name' => 'ShieldInventory',
                'description' => 'Comprehensive asset discovery, inventory management, and automated health tracking.',
                'status' => 'maintenance',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldrespond'],
            [
                'name' => 'ShieldRespond',
                'description' => 'Automated incident response and orchestration for rapid recovery and resolution.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldsecure'],
            [
                'name' => 'ShieldSecure',
                'description' => 'Security operations center for threat monitoring, vulnerability scanning, and posture defense.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldnotify'],
            [
                'name' => 'ShieldNotify',
                'description' => 'Alert and notification management across email, SMS, and in-app channels.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldvoice'],
            [
                'name' => 'ShieldVoice',
                'description' => 'AI voice and IVR operations for automated customer support and service analytics.',
                'status' => 'inactive',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldknowledge'],
            [
                'name' => 'ShieldKnowledge',
                'description' => 'Knowledge management for documentation, playbooks, and operational procedures.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldautomate'],
            [
                'name' => 'ShieldAutomate',
                'description' => 'Workflow automation and process orchestration across systems and teams.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shieldbalance'],
            [
                'name' => 'ShieldBalance',
                'description' => 'Load balancing and traffic management for optimal resource distribution.',
                'status' => 'active',
            ]
        );

        Module::updateOrCreate(
            ['key' => 'shielddesk'],
            [
                'name' => 'ShieldDesk',
                'description' => 'Help desk operations for ticketing, SLA compliance, and customer satisfaction.',
                'status' => 'active',
            ]
        );

        // Add integrations module (used by gateway)
        Module::updateOrCreate(
            ['key' => 'shieldintegrations'],
            [
                'name' => 'ShieldIntegrations',
                'description' => 'Third-party integrations and API connections for external services.',
                'status' => 'active',
            ]
        );
    }
}

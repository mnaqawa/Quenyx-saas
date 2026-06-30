<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Authoritative list of Quenyx modules (Qyn*).
     * This is the source of truth - only these modules should exist.
     */
    // NOTE (v1.0.0): 'qyncore' and 'qynintegrations' are NOT customer-facing business modules.
    // 'qyncore' is the platform core (billing/subscriptions/governance) and 'qynintegrations' is the
    // entitlement key for the Integrations platform capability (external systems only). Both are kept
    // in this table for backward compatibility with existing plans, subscriptions, overrides, and the
    // gateway entitlement gate. Do not surface them as navigable business modules.
    private const QUENYX_MODULES = [
        ['key' => 'qyncore', 'name' => 'QynCore', 'description' => 'Platform core: billing, subscriptions, configuration, and governance (not a navigable business module).', 'status' => 'active'],
        ['key' => 'qynsight', 'name' => 'QynSight', 'description' => 'Real-time infrastructure monitoring and performance insights across your environment.', 'status' => 'active'],
        ['key' => 'qynasset', 'name' => 'QynAsset', 'description' => 'Comprehensive asset discovery, inventory management, and automated health tracking.', 'status' => 'maintenance'],
        ['key' => 'qynreact', 'name' => 'QynReact', 'description' => 'Automated incident response and orchestration for rapid recovery and resolution.', 'status' => 'active'],
        ['key' => 'qynshield', 'name' => 'QynShield', 'description' => 'Security operations center for threat monitoring, vulnerability scanning, and posture defense.', 'status' => 'active'],
        ['key' => 'qynnotify', 'name' => 'QynNotify', 'description' => 'Alert and notification management across email, SMS, and in-app channels.', 'status' => 'active'],
        ['key' => 'qynva', 'name' => 'QynVA', 'description' => 'AI voice and IVR operations for automated customer support and service analytics.', 'status' => 'inactive'],
        ['key' => 'qynknow', 'name' => 'QynKnow', 'description' => 'Knowledge management for documentation, playbooks, and operational procedures.', 'status' => 'active'],
        ['key' => 'qynrun', 'name' => 'QynRun', 'description' => 'Workflow automation and process orchestration across systems and teams.', 'status' => 'active'],
        ['key' => 'qynbalance', 'name' => 'QynBalance', 'description' => 'Load balancing and traffic management for optimal resource distribution.', 'status' => 'active'],
        ['key' => 'qynsupport', 'name' => 'QynSupport', 'description' => 'Help desk operations for ticketing, SLA compliance, and customer satisfaction.', 'status' => 'active'],
        // Entitlement key for the Integrations platform capability (external systems only) — gates
        // /api/{projects|workspaces}/{id}/integrations* at the gateway. Not a business module.
        ['key' => 'qynintegrations', 'name' => 'QynIntegrations', 'description' => 'Entitlement key for the Integrations platform capability (external systems only); not a business module.', 'status' => 'active'],
    ];

    public function run(): void
    {
        foreach (self::QUENYX_MODULES as $data) {
            Module::updateOrCreate(
                ['key' => $data['key']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'status' => $data['status'],
                ]
            );
        }
    }
}

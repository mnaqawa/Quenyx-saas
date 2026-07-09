<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Constants\AgentConstants;
use App\Constants\AgentResourceType;
use App\Models\Agent;
use App\Models\AgentGateway;
use App\Models\AgentManagedResource;
use App\Models\AgentPlugin;
use App\Models\PlatformAsset;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sync managed resources, assets, and plugins from agent enrollment/heartbeat.
 */
class AgentManagedResourceService
{
    public function __construct(
        private readonly AgentGatewayService $gatewayService,
    ) {}

    public function bootstrapOnRegister(Agent $agent, ?int $monitoringTargetId = null): ?AgentManagedResource
    {
        if (! Schema::hasTable('agent_managed_resources')) {
            return null;
        }

        $gateway = $this->gatewayService->resolvePreferredGateway($agent->workspace_id);
        if ($gateway && ! $agent->preferred_gateway_id) {
            $agent->update(['preferred_gateway_id' => $gateway->id]);
        }

        $resource = AgentManagedResource::firstOrCreate(
            [
                'agent_id' => $agent->id,
                'resource_type' => AgentResourceType::LOCAL_HOST,
                'display_name' => $agent->hostname,
            ],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $agent->workspace_id,
                'lifecycle_status' => 'active',
                'health_status' => 'online',
                'last_seen_at' => now(),
                'metadata' => [
                    'os' => $agent->os,
                    'arch' => $agent->arch,
                ],
            ]
        );

        if (Schema::hasTable('platform_assets')) {
            PlatformAsset::firstOrCreate(
                [
                    'agent_id' => $agent->id,
                    'managed_resource_id' => $resource->id,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $agent->workspace_id,
                    'monitoring_target_id' => $monitoringTargetId,
                    'name' => $agent->hostname,
                    'asset_type' => 'server',
                    'lifecycle_status' => 'active',
                    'health_status' => 'online',
                    'metadata' => ['source' => 'agent_enrollment'],
                ]
            );
        }

        if (Schema::hasTable('agent_plugins')) {
            $this->seedDefaultPlugins($agent);
        }

        return $resource;
    }

    /**
     * @param list<array<string, mixed>> $resources
     */
    public function syncFromHeartbeat(Agent $agent, array $resources): void
    {
        foreach ($resources as $row) {
            $type = (string) ($row['resource_type'] ?? $row['type'] ?? '');
            $name = (string) ($row['display_name'] ?? $row['name'] ?? '');
            if ($type === '' || $name === '') {
                continue;
            }

            $uuid = isset($row['uuid']) ? (string) $row['uuid'] : null;
            $query = AgentManagedResource::where('agent_id', $agent->id)
                ->where('resource_type', $type)
                ->where('display_name', $name);

            if ($uuid) {
                $query = AgentManagedResource::where('id', $uuid);
            }

            $resource = $query->first();
            $payload = [
                'workspace_id' => $agent->workspace_id,
                'resource_type' => $type,
                'display_name' => $name,
                'lifecycle_status' => (string) ($row['lifecycle_status'] ?? 'active'),
                'health_status' => (string) ($row['health_status'] ?? $row['health'] ?? 'unknown'),
                'last_seen_at' => now(),
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            ];

            if ($resource) {
                $resource->update($payload);
            } else {
                $resource = AgentManagedResource::create(array_merge($payload, [
                    'id' => $uuid ?: (string) Str::uuid(),
                    'agent_id' => $agent->id,
                ]));
            }

            $isMonitoring = (bool) ($row['is_monitoring_target'] ?? ($type === AgentResourceType::LOCAL_HOST));
            if (! $isMonitoring && Schema::hasTable('platform_assets')) {
                PlatformAsset::firstOrCreate(
                    [
                        'agent_id' => $agent->id,
                        'name' => $name,
                        'asset_type' => $type,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $agent->workspace_id,
                        'managed_resource_id' => $resource->id,
                        'lifecycle_status' => $payload['lifecycle_status'],
                        'health_status' => $payload['health_status'],
                        'metadata' => $payload['metadata'],
                    ]
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $plugins
     */
    public function syncPlugins(Agent $agent, array $plugins): void
    {
        foreach ($plugins as $row) {
            $key = (string) ($row['plugin_key'] ?? $row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $existing = AgentPlugin::where('agent_id', $agent->id)->where('plugin_key', $key)->first();
            $attrs = array_filter([
                'name' => (string) ($row['name'] ?? $key),
                'version' => $row['version'] ?? null,
                'vendor' => $row['vendor'] ?? 'Quenyx',
                'description' => $row['description'] ?? null,
                'status' => (string) ($row['status'] ?? 'active'),
                'health_status' => (string) ($row['health_status'] ?? $row['health'] ?? 'unknown'),
                'last_execution_at' => isset($row['last_execution_at']) ? Carbon::parse($row['last_execution_at']) : null,
                'error_count' => (int) ($row['error_count'] ?? 0),
                'required_permissions' => $row['required_permissions'] ?? null,
                'dependencies' => $row['dependencies'] ?? null,
                'configuration_version' => $row['configuration_version'] ?? null,
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            ], fn ($v) => $v !== null);

            if ($existing) {
                $existing->update($attrs);
            } else {
                AgentPlugin::create(array_merge($attrs, [
                    'id' => (string) Str::uuid(),
                    'agent_id' => $agent->id,
                    'plugin_key' => $key,
                ]));
            }
        }
    }

    private function seedDefaultPlugins(Agent $agent): void
    {
        $defaults = [
            ['plugin_key' => 'monitoring', 'name' => 'Monitoring', 'required_permissions' => ['system_metrics', 'filesystem']],
            ['plugin_key' => 'inventory', 'name' => 'Inventory', 'required_permissions' => ['inventory']],
            ['plugin_key' => 'network', 'name' => 'Network', 'required_permissions' => ['network']],
        ];

        foreach ($defaults as $plugin) {
            AgentPlugin::firstOrCreate(
                ['agent_id' => $agent->id, 'plugin_key' => $plugin['plugin_key']],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $plugin['name'],
                    'version' => AgentConstants::AGENT_VERSION,
                    'vendor' => 'Quenyx',
                    'status' => 'active',
                    'health_status' => 'unknown',
                    'required_permissions' => $plugin['required_permissions'],
                ]
            );
        }
    }
}

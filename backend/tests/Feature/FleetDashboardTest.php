<?php

namespace Tests\Feature;

use App\Constants\AgentConstants;
use App\Constants\AgentPolicyStatus;
use App\Constants\AgentResourceType;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentGateway;
use App\Models\AgentManagedResource;
use App\Models\AgentPlugin;
use App\Models\PlatformAsset;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Services\Asset\Intelligence\AssetEvidenceCollector;
use App\Services\PlatformAgent\AgentGatewayService;
use App\Services\PlatformAgent\FleetDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FleetDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlans(): void
    {
        $this->artisan('db:seed', ['--class' => 'PlanSeeder']);
        $this->artisan('db:seed', ['--class' => 'ModuleSeeder']);
    }

    private function makeUserAndProject(): array
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'fleet@test.com',
            'password' => Hash::make('password'),
        ]);
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'Fleet WS',
            'status' => 'active',
        ]);
        $pro = Plan::where('key', 'pro')->first();
        if ($pro) {
            ProjectSubscription::create([
                'project_id' => $project->id,
                'plan_id' => $pro->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);
        }

        return [$user, $project];
    }

    public function test_registration_creates_managed_resource_and_plugins(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        $token = AgentEnrollmentToken::generateToken();
        AgentEnrollmentToken::create([
            'workspace_id' => $project->id,
            'token_hash' => hash('sha256', $token),
            'primary_protocol' => AgentConstants::PROTOCOL_QAG,
            'enabled_protocols' => [AgentConstants::PROTOCOL_QAG],
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/agents/register', [
            'workspace_id' => $project->id,
            'token' => $token,
            'hostname' => 'multi-res-host',
            'os' => 'linux',
        ], ['X-Quenyx-Agent-Gateway' => '1']);

        $response->assertStatus(200);
        $agentId = $response->json('data.agent_id');

        $this->assertDatabaseHas('agent_managed_resources', [
            'agent_id' => $agentId,
            'resource_type' => AgentResourceType::LOCAL_HOST,
            'display_name' => 'multi-res-host',
        ]);
        $this->assertDatabaseHas('platform_assets', ['agent_id' => $agentId]);
        $this->assertGreaterThanOrEqual(3, AgentPlugin::where('agent_id', $agentId)->count());
    }

    public function test_heartbeat_accepts_policy_versioning_and_syncs_resources(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        $secret = 'hb-secret';
        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'policy-host',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'capabilities' => ['monitoring.telemetry'],
            'agent_secret_hash' => Hash::make($secret),
            'status' => AgentConstants::STATUS_ONLINE,
            'lifecycle_status' => 'online',
            'enrolled_at' => now(),
            'last_seen_at' => now()->subHour(),
        ]);

        $response = $this->postJson("/api/agents/{$agent->id}/heartbeat", [
            'agent_version' => '1.0.0',
            'policy_version' => '1.0.0',
            'platform_version' => '1.0.0',
            'plugin_versions' => ['monitoring' => '1.0.0'],
            'managed_resources' => [
                [
                    'resource_type' => AgentResourceType::DOCKER_CONTAINER,
                    'display_name' => 'app-container',
                    'is_monitoring_target' => false,
                    'health_status' => 'healthy',
                ],
            ],
            'plugins' => [
                ['plugin_key' => 'monitoring', 'name' => 'Monitoring', 'health_status' => 'healthy', 'error_count' => 0],
            ],
        ], [
            'X-Agent-Secret' => $secret,
            'X-Quenyx-Agent-Gateway' => '1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure(['data' => ['policy' => ['policy_version', 'policy_status']]]);

        $agent->refresh();
        $this->assertSame(AgentPolicyStatus::UP_TO_DATE, $agent->policy_status);
        $this->assertDatabaseHas('agent_managed_resources', [
            'agent_id' => $agent->id,
            'resource_type' => AgentResourceType::DOCKER_CONTAINER,
            'display_name' => 'app-container',
        ]);
        $this->assertDatabaseHas('platform_assets', [
            'agent_id' => $agent->id,
            'asset_type' => AgentResourceType::DOCKER_CONTAINER,
            'name' => 'app-container',
        ]);
    }

    public function test_fleet_dashboard_api_returns_aggregates(): void
    {
        $this->seedPlans();
        [$user, $project] = $this->makeUserAndProject();
        Sanctum::actingAs($user, ['*']);

        Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'online-agent',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'agent_secret_hash' => Hash::make('x'),
            'status' => 'online',
            'lifecycle_status' => 'online',
            'policy_status' => AgentPolicyStatus::UP_TO_DATE,
            'last_seen_at' => now(),
            'enrolled_at' => now(),
            'heartbeat_count' => 10,
        ]);

        $response = $this->getJson('/api/platform/agents/fleet?workspace_id='.$project->id);
        $response->assertStatus(200)
            ->assertJsonPath('data.fleet_summary.total', 1)
            ->assertJsonPath('data.fleet_summary.online', 1)
            ->assertJsonStructure([
                'data' => [
                    'fleet_summary',
                    'version_summary',
                    'policy_summary',
                    'gateway_summary',
                    'heartbeat_statistics',
                ],
            ]);
    }

    public function test_installer_catalog_api(): void
    {
        [$user, $project] = $this->makeUserAndProject();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/platform/agents/installers?workspace_id='.$project->id);
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['installers' => ['linux', 'windows', 'macos', 'container']]]);
    }

    public function test_gateway_failover_returned_when_primary_unhealthy(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        AgentGateway::query()->delete();

        $primary = AgentGateway::create([
            'id' => (string) Str::uuid(),
            'name' => 'Primary',
            'endpoint_url' => 'https://gw1.example:9444',
            'health_status' => 'unhealthy',
            'is_primary' => true,
        ]);
        $secondary = AgentGateway::create([
            'id' => (string) Str::uuid(),
            'name' => 'Secondary',
            'endpoint_url' => 'https://gw2.example:9444',
            'health_status' => 'healthy',
            'is_primary' => false,
        ]);

        $secret = 'gw-secret';
        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'gw-host',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'agent_secret_hash' => Hash::make($secret),
            'preferred_gateway_id' => $primary->id,
            'status' => 'online',
            'enrolled_at' => now(),
        ]);

        $response = $this->postJson("/api/agents/{$agent->id}/heartbeat", [], [
            'X-Agent-Secret' => $secret,
            'X-Quenyx-Agent-Gateway' => '1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.failover_gateway.gateway_uuid', $secondary->id);
    }

    public function test_inventory_only_platform_assets_in_qynasset_evidence(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        PlatformAsset::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'name' => 'Office Printer',
            'asset_type' => 'printer',
            'lifecycle_status' => 'active',
            'monitoring_target_id' => null,
        ]);

        $inventory = app(AssetEvidenceCollector::class)->inventory($project);
        $names = array_column($inventory, 'name');
        $this->assertContains('Office Printer', $names);

        $printer = collect($inventory)->firstWhere('name', 'Office Printer');
        $this->assertFalse($printer['is_monitoring_target'] ?? true);
    }

    public function test_fleet_service_counts_stale_agents_offline(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'stale',
            'permissions' => [],
            'agent_secret_hash' => Hash::make('x'),
            'status' => 'online',
            'lifecycle_status' => 'online',
            'last_seen_at' => now()->subHours(2),
            'enrolled_at' => now()->subDay(),
        ]);

        $fleet = app(FleetDashboardService::class)->build($project);
        $this->assertSame(1, $fleet['fleet_summary']['offline']);
    }

    public function test_gateway_service_resolves_primary(): void
    {
        $gw = app(AgentGatewayService::class)->ensureDefaultExists();
        $resolved = app(AgentGatewayService::class)->resolvePreferredGateway();
        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is_primary || $resolved->id === $gw->id);
    }
}

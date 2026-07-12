<?php

namespace Tests\Feature;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentMetric;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Services\PlatformAgent\AgentTelemetryObserveBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAgentTest extends TestCase
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
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
        ]);
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'WS',
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

    public function test_agent_registration_returns_capability_policy(): void
    {
        $this->seedPlans();
        [$user, $project] = $this->makeUserAndProject();

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
            'hostname' => 'host1',
            'private_ip' => '10.0.0.5',
            'public_ip' => '203.0.113.10',
            'os' => 'linux',
            'arch' => 'amd64',
        ], ['X-Quenyx-Agent-Gateway' => '1']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['agent_id', 'agent_secret', 'capabilities', 'capability_policy'],
            ]);

        $agentId = $response->json('data.agent_id');
        $this->assertDatabaseHas('agents', [
            'id' => $agentId,
            'hostname' => 'host1',
        ]);

        $host = ObserveTargetHost::where('agent_id', $agentId)->first();
        $this->assertNotNull($host);
        $this->assertSame('agent', $host->source);

        $telemetryChecks = ObserveTargetService::where('host_id', $host->id)
            ->where('check_source', AgentConstants::CHECK_SOURCE_PLATFORM_AGENT)
            ->count();
        $this->assertGreaterThan(0, $telemetryChecks);
    }

    public function test_telemetry_bridge_updates_observe_state_from_agent_metrics(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'telemetry-host',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'capabilities' => ['monitoring.telemetry'],
            'agent_secret_hash' => Hash::make('secret'),
            'status' => AgentConstants::STATUS_ONLINE,
            'enrolled_at' => now(),
            'last_seen_at' => now(),
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'telemetry-host',
            'address' => '10.0.0.8',
            'agent_id' => $agent->id,
            'source' => 'agent',
            'enabled' => true,
        ]);

        ObserveTargetService::create([
            'workspace_id' => $project->id,
            'host_id' => $host->id,
            'name' => 'cpu',
            'service_key' => 'cpu',
            'check_command' => 'platform_agent_telemetry',
            'check_source' => AgentConstants::CHECK_SOURCE_PLATFORM_AGENT,
            'check_args' => ['warn_pct' => 80, 'crit_pct' => 90],
            'enabled' => true,
        ]);

        AgentMetric::create([
            'agent_id' => $agent->id,
            'collected_at' => now(),
            'payload' => [
                'cpu' => ['used_pct' => 42.5, 'cores' => 4],
                'memory' => ['total' => 1000, 'available' => 500, 'used_pct' => 50],
                'load' => ['load1' => 0.5],
            ],
        ]);

        $bridge = app(AgentTelemetryObserveBridge::class);
        $existing = [];
        $updated = $bridge->syncHost($host, $existing, now());

        $this->assertGreaterThan(0, $updated);
        $this->assertArrayHasKey('ws'.$project->id.'-telemetry-host::cpu', $existing);
        $this->assertSame('ok', $existing['ws'.$project->id.'-telemetry-host::cpu']->state);
        $this->assertStringContainsString('Platform Agent', $existing['ws'.$project->id.'-telemetry-host::cpu']->output);
        $this->assertArrayHasKey('ws'.$project->id.'-telemetry-host::Host-Alive', $existing);
        $this->assertSame('ok', $existing['ws'.$project->id.'-telemetry-host::Host-Alive']->state);
    }

    public function test_telemetry_bridge_marks_stale_metrics_and_critical_host_alive(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'stale-host',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'capabilities' => ['monitoring.telemetry'],
            'agent_secret_hash' => Hash::make('secret'),
            'status' => AgentConstants::STATUS_ONLINE,
            'enrolled_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(2),
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'stale-host',
            'address' => '10.0.0.9',
            'agent_id' => $agent->id,
            'source' => 'agent',
            'enabled' => true,
        ]);

        ObserveTargetService::create([
            'workspace_id' => $project->id,
            'host_id' => $host->id,
            'name' => 'cpu',
            'service_key' => 'cpu',
            'check_command' => 'platform_agent_telemetry',
            'check_source' => AgentConstants::CHECK_SOURCE_PLATFORM_AGENT,
            'check_args' => ['warn_pct' => 80, 'crit_pct' => 90],
            'enabled' => true,
        ]);

        ObserveTargetService::create([
            'workspace_id' => $project->id,
            'host_id' => $host->id,
            'name' => 'disk',
            'service_key' => 'disk',
            'check_command' => 'platform_agent_telemetry',
            'check_source' => AgentConstants::CHECK_SOURCE_PLATFORM_AGENT,
            'check_args' => ['warn_pct' => 20, 'crit_pct' => 10],
            'enabled' => true,
        ]);

        AgentMetric::create([
            'agent_id' => $agent->id,
            'collected_at' => now()->subHours(2),
            'payload' => [
                'cpu' => ['used_pct' => 10.0, 'cores' => 2],
                'disk' => [],
            ],
        ]);

        $bridge = app(AgentTelemetryObserveBridge::class);
        $existing = [];
        $bridge->syncHost($host, $existing, now());

        $hostAlive = $existing['ws'.$project->id.'-stale-host::Host-Alive'] ?? null;
        $cpu = $existing['ws'.$project->id.'-stale-host::cpu'] ?? null;
        $disk = $existing['ws'.$project->id.'-stale-host::disk'] ?? null;

        $this->assertNotNull($hostAlive);
        $this->assertSame('critical', $hostAlive->state);
        $this->assertStringContainsString('no recent Platform Agent heartbeat', $hostAlive->output);

        $this->assertNotNull($cpu);
        $this->assertSame('warning', $cpu->state);
        $this->assertStringContainsString('Stale Platform Agent telemetry', $cpu->output);

        $this->assertNotNull($disk);
        $this->assertSame('unknown', $disk->state);
        $this->assertStringContainsString('disk telemetry', strtolower((string) $disk->output));
    }

    public function test_telemetry_bridge_evaluates_disk_free_percent(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'disk-host',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'capabilities' => ['monitoring.telemetry'],
            'agent_secret_hash' => Hash::make('secret'),
            'status' => AgentConstants::STATUS_ONLINE,
            'enrolled_at' => now(),
            'last_seen_at' => now(),
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'disk-host',
            'address' => '10.0.0.10',
            'agent_id' => $agent->id,
            'source' => 'agent',
            'enabled' => true,
        ]);

        ObserveTargetService::create([
            'workspace_id' => $project->id,
            'host_id' => $host->id,
            'name' => 'disk',
            'service_key' => 'disk',
            'check_command' => 'platform_agent_telemetry',
            'check_source' => AgentConstants::CHECK_SOURCE_PLATFORM_AGENT,
            'check_args' => ['warn_pct' => 20, 'crit_pct' => 10],
            'enabled' => true,
        ]);

        AgentMetric::create([
            'agent_id' => $agent->id,
            'collected_at' => now(),
            'payload' => [
                'disk' => [
                    '/' => [
                        'total' => 1000,
                        'used' => 400,
                        'free' => 600,
                        'used_pct' => 40.0,
                        'free_pct' => 60.0,
                    ],
                ],
            ],
        ]);

        $bridge = app(AgentTelemetryObserveBridge::class);
        $existing = [];
        $bridge->syncHost($host, $existing, now());

        $disk = $existing['ws'.$project->id.'-disk-host::disk'] ?? null;
        $this->assertNotNull($disk);
        $this->assertSame('ok', $disk->state);
        $this->assertStringContainsString('60.0% free', $disk->output);
    }

    public function test_evidence_endpoint_disabled_without_permission(): void
    {
        $this->seedPlans();
        [, $project] = $this->makeUserAndProject();

        $secret = 'test-secret';
        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'evidence-host',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'agent_secret_hash' => Hash::make($secret),
            'status' => AgentConstants::STATUS_ONLINE,
            'enrolled_at' => now(),
        ]);

        $response = $this->postJson("/api/agents/{$agent->id}/evidence", [
            'collected_at' => now()->toIso8601String(),
            'payload' => ['snapshot' => 'test'],
        ], [
            'X-Agent-Secret' => $secret,
            'X-Quenyx-Agent-Gateway' => '1',
        ]);

        $response->assertStatus(403);
    }

    public function test_platform_agent_metadata_endpoint(): void
    {
        [$user, $project] = $this->makeUserAndProject();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/platform/agents/metadata');
        $response->assertStatus(200)
            ->assertJsonPath('data.agent_type', AgentConstants::AGENT_TYPE)
            ->assertJsonStructure(['data' => ['gateway_url', 'capabilities', 'default_permissions']]);
    }
}

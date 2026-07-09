<?php

namespace Tests\Feature;

use App\Constants\AgentConstants;
use App\Constants\AgentHealthLevel;
use App\Constants\AgentPolicyStatus;
use App\Constants\AgentUpdateChannel;
use App\Constants\AgentUpdateStatus;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentRelease;
use App\Models\AgentUpdateAssignment;
use App\Models\Project;
use App\Models\User;
use App\Services\PlatformAgent\AgentConfigurationService;
use App\Services\PlatformAgent\AgentHealthScoringService;
use App\Services\PlatformAgent\AgentOfflineQueueService;
use App\Services\PlatformAgent\AgentUpdateService;
use App\Services\PlatformAgent\FleetIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAgentOperationalMaturityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndProject(): array
    {
        $user = User::create([
            'name' => 'Ops Admin',
            'email' => 'ops@test.com',
            'password' => Hash::make('password'),
        ]);
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'Ops WS',
            'status' => 'active',
        ]);

        return [$user, $project];
    }

    private function enrollAgent(Project $project): array
    {
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
            'hostname' => 'ops-host-1',
            'os' => 'linux',
            'arch' => 'amd64',
            'agent_version' => '1.0.0',
        ]);

        $response->assertOk();
        $secret = $response->json('data.agent_secret');
        $agentId = $response->json('data.agent_id');

        return [$agentId, $secret];
    }

    public function test_heartbeat_returns_configuration_update_and_health(): void
    {
        [, $project] = $this->makeUserAndProject();
        [$agentId, $secret] = $this->enrollAgent($project);

        $response = $this->postJson("/api/agents/{$agentId}/heartbeat", [
            'agent_version' => '1.0.0',
            'policy_version' => '1.0.0',
            'queue_stats' => ['queued_events' => 2, 'dropped_events' => 0],
        ], ['X-Agent-Secret' => $secret]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'policy',
                    'configuration' => ['version', 'settings'],
                    'update',
                    'certificate',
                    'health' => ['score', 'level'],
                ],
            ]);
    }

    public function test_health_scoring_persists_on_heartbeat(): void
    {
        [, $project] = $this->makeUserAndProject();
        [$agentId, $secret] = $this->enrollAgent($project);

        $this->postJson("/api/agents/{$agentId}/heartbeat", [
            'agent_version' => '1.0.0',
            'policy_version' => '1.0.0',
        ], ['X-Agent-Secret' => $secret])->assertOk();

        $agent = Agent::findOrFail($agentId);
        $this->assertNotNull($agent->health_score);
        $this->assertNotNull($agent->health_level);
        $this->assertIsArray($agent->health_breakdown);
    }

    public function test_health_scoring_service_weights(): void
    {
        [, $project] = $this->makeUserAndProject();
        $agent = Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'healthy-host',
            'agent_version' => '1.0.0',
            'policy_status' => AgentPolicyStatus::UP_TO_DATE,
            'lifecycle_status' => 'online',
            'last_seen_at' => now(),
            'status' => 'online',
            'agent_secret_hash' => Hash::make('x'),
            'enrolled_at' => now(),
        ]);

        $service = app(AgentHealthScoringService::class);
        $result = $service->compute($agent);

        $this->assertGreaterThanOrEqual(50, $result['score']);
        $this->assertContains($result['level'], [
            AgentHealthLevel::HEALTHY,
            AgentHealthLevel::WARNING,
        ]);
        $this->assertArrayHasKey('heartbeat_freshness', $result['breakdown']);
    }

    public function test_update_never_proceeds_without_approval(): void
    {
        [, $project] = $this->makeUserAndProject();
        $agent = Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'update-host',
            'os' => 'linux',
            'arch' => 'amd64',
            'agent_version' => '0.9.0',
            'update_channel' => AgentUpdateChannel::STABLE,
            'status' => 'online',
            'agent_secret_hash' => Hash::make('x'),
            'enrolled_at' => now(),
        ]);

        $release = AgentRelease::create([
            'id' => (string) Str::uuid(),
            'version' => '1.1.0',
            'channel' => AgentUpdateChannel::STABLE,
            'platform' => 'linux',
            'arch' => 'amd64',
            'download_url' => 'https://example.com/agent',
            'checksum_sha256' => hash('sha256', 'test'),
            'mandatory' => true,
            'is_latest' => true,
            'published_at' => now(),
        ]);

        $instruction = app(AgentUpdateService::class)->heartbeatInstruction($agent->fresh());

        $this->assertNotNull($instruction);
        $this->assertFalse($instruction['may_proceed'] ?? true);
        $this->assertNull($instruction['download_url'] ?? null);
    }

    public function test_update_proceeds_when_approved_in_maintenance_window(): void
    {
        [, $project] = $this->makeUserAndProject();
        $agent = Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'approved-host',
            'os' => 'linux',
            'arch' => 'amd64',
            'agent_version' => '0.9.0',
            'update_channel' => AgentUpdateChannel::STABLE,
            'status' => 'online',
            'agent_secret_hash' => Hash::make('x'),
            'enrolled_at' => now(),
        ]);

        $release = AgentRelease::create([
            'id' => (string) Str::uuid(),
            'version' => '1.1.0',
            'channel' => AgentUpdateChannel::STABLE,
            'platform' => 'linux',
            'arch' => 'amd64',
            'download_url' => 'https://example.com/agent-linux',
            'checksum_sha256' => hash('sha256', 'approved'),
            'mandatory' => true,
            'is_latest' => true,
            'published_at' => now(),
        ]);

        $assignment = AgentUpdateAssignment::create([
            'id' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'release_id' => $release->id,
            'workspace_id' => $project->id,
            'status' => AgentUpdateStatus::APPROVED,
            'approved' => true,
            'maintenance_window_start' => now()->subHour(),
            'maintenance_window_end' => now()->addHour(),
        ]);

        $instruction = app(AgentUpdateService::class)->heartbeatInstruction($agent->fresh());

        $this->assertTrue($instruction['may_proceed']);
        $this->assertNotEmpty($instruction['download_url']);
    }

    public function test_offline_queue_deduplication(): void
    {
        [, $project] = $this->makeUserAndProject();
        $agent = Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'queue-host',
            'status' => 'online',
            'agent_secret_hash' => Hash::make('x'),
            'enrolled_at' => now(),
        ]);

        $service = app(AgentOfflineQueueService::class);
        $result = $service->ingestReplay($agent, [
            ['event_type' => 'telemetry', 'dedup_key' => 'k1', 'payload' => ['cpu' => 10], 'event_at' => now()->toIso8601String()],
            ['event_type' => 'telemetry', 'dedup_key' => 'k1', 'payload' => ['cpu' => 10], 'event_at' => now()->toIso8601String()],
        ]);

        $this->assertSame(1, $result['accepted']);
        $this->assertSame(1, $result['duplicate']);
    }

    public function test_configuration_sync_records_version(): void
    {
        [, $project] = $this->makeUserAndProject();
        $agent = Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'cfg-host',
            'status' => 'online',
            'agent_secret_hash' => Hash::make('x'),
            'enrolled_at' => now(),
        ]);

        app(AgentConfigurationService::class)->recordSync($agent, '1.0.1');
        $this->assertSame('1.0.1', $agent->fresh()->config_version);
    }

    public function test_platform_operational_apis_require_auth(): void
    {
        [, $project] = $this->makeUserAndProject();

        $this->getJson('/api/platform/agents/health?workspace_id='.$project->id)->assertUnauthorized();
        $this->getJson('/api/platform/agents/updates?workspace_id='.$project->id)->assertUnauthorized();
        $this->getJson('/api/platform/agents/configuration?workspace_id='.$project->id)->assertUnauthorized();
        $this->getJson('/api/platform/agents/certificates?workspace_id='.$project->id)->assertUnauthorized();
        $this->getJson('/api/platform/agents/queue?workspace_id='.$project->id)->assertUnauthorized();
        $this->getJson('/api/platform/fleet/summary?workspace_id='.$project->id)->assertUnauthorized();
    }

    public function test_platform_operational_apis_return_data(): void
    {
        [$user, $project] = $this->makeUserAndProject();
        Sanctum::actingAs($user);

        $this->getJson('/api/platform/agents/health?workspace_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/platform/fleet/summary?workspace_id='.$project->id)
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['fleet_summary', 'health', 'updates']]);
    }

    public function test_fleet_intelligence_uses_real_data(): void
    {
        [, $project] = $this->makeUserAndProject();
        Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'stale-host',
            'agent_version' => '0.5.0',
            'policy_status' => AgentPolicyStatus::POLICY_OUTDATED,
            'health_level' => AgentHealthLevel::CRITICAL,
            'health_score' => 25,
            'last_seen_at' => now()->subHours(2),
            'status' => 'offline',
            'agent_secret_hash' => Hash::make('x'),
            'enrolled_at' => now(),
        ]);

        $intel = app(FleetIntelligenceService::class);
        $unhealthy = $intel->unhealthyAgents($project);
        $stale = $intel->stalePolicyAgents($project);

        $this->assertCount(1, $unhealthy);
        $this->assertCount(1, $stale);
        $this->assertStringContainsString('FLEET OPERATIONAL INTELLIGENCE', $intel->toPromptBlock($project));
    }

    public function test_diagnostics_upload_and_list(): void
    {
        [, $project] = $this->makeUserAndProject();
        [$agentId, $secret] = $this->enrollAgent($project);

        $this->postJson("/api/agents/{$agentId}/diagnostics", [
            'bundle' => [
                'agent_version' => '1.0.0',
                'plugins' => [],
            ],
        ], ['X-Agent-Secret' => $secret])->assertOk();

        $user = User::find($project->owner_id);
        Sanctum::actingAs($user);

        $this->getJson("/api/platform/agents/{$agentId}/diagnostics")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}

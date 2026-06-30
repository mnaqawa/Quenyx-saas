<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentInventory;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Models\User;
use App\Support\Asset\AssetEntityId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 22 — QynAsset Asset Intelligence + AI Adapter Platform. Validates the full pipeline in safe
 * (mock) mode: registry discovery, UUID-only + workspace-scoped + RBAC envelope, real evidence (no
 * fabricated inventory), and shared AI narration reuse.
 */
class AssetIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $workspace;

    private int $hostId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Asset User',
            'email' => 'asset@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->workspace = Project::create([
            'owner_id' => $this->user->id,
            'name' => 'Asset Workspace',
            'status' => 'active',
        ]);

        $agentId = (string) Str::uuid();
        Agent::create([
            'id' => $agentId,
            'workspace_id' => $this->workspace->id,
            'hostname' => 'app-01',
            'os' => 'linux',
            'arch' => 'amd64',
            'agent_version' => '1.0.0',
            'agent_secret_hash' => str_repeat('a', 64),
            'status' => 'online',
            'last_seen_at' => now(),
            'enrolled_at' => now()->subDays(2),
        ]);

        AgentInventory::create([
            'agent_id' => $agentId,
            'collected_at' => now(),
            'payload' => ['hostname' => 'app-01', 'os' => 'linux', 'arch' => 'amd64', 'cpu_cores' => 4, 'agent_version' => '1.0.0'],
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'app-01',
            'address' => '10.0.0.5',
            'agent_id' => $agentId,
            'source' => 'agent',
            'check_command' => 'check-host-alive',
            'enabled' => true,
        ]);
        $this->hostId = (int) $host->id;
    }

    public function test_adapter_registry_discovers_modules_dynamically(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/adapters?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['adapters' => [['module_key', 'module_name', 'capabilities', 'available_actions']], 'count']]);

        $keys = array_column($response->json('data.adapters'), 'module_key');
        $this->assertContains('qynsight', $keys);
        $this->assertContains('qynasset', $keys);
    }

    public function test_adapter_show_and_actions_are_discoverable(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/ai/adapters/qynasset?workspace='.$this->workspace->uuid)
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['module_key' => 'qynasset']]);

        $this->actingAs($this->user)
            ->getJson('/api/ai/actions?workspace='.$this->workspace->uuid)
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['actions' => [['module', 'key', 'capability', 'endpoint']], 'count']]);
    }

    public function test_asset_overview_returns_real_inventory(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynasset/intelligence/overview?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'inventory_summary' => ['total', 'with_agent', 'by_os', 'discovery_confidence'],
                    'discovery' => ['new_asset_count', 'unknown_asset_count', 'duplicate_count'],
                    'recent_recommendations',
                    'recent_ai_investigations',
                ],
            ]);

        $this->assertSame(1, $response->json('data.inventory_summary.total'));
        $this->assertSame(1, $response->json('data.inventory_summary.with_agent'));
    }

    public function test_asset_copilot_reuses_quenyx_ai_conversation_in_mock_mode(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynasset/intelligence/copilot', [
            'workspace' => $this->workspace->uuid,
            'message' => 'Which assets are inactive?',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['conversation_uuid', 'message_uuid', 'answer' => ['mocked', 'ai_enabled'], 'evidence']]);

        $this->assertTrue($response->json('data.answer.mocked'));
        $this->assertFalse($response->json('data.answer.ai_enabled'));
    }

    public function test_asset_explain_is_uuid_scoped(): void
    {
        $uuid = AssetEntityId::for(AssetEntityId::TYPE_ASSET, $this->workspace->id, $this->hostId);

        $response = $this->actingAs($this->user)
            ->postJson("/api/qynasset/intelligence/assets/{$uuid}/explain", [
                'workspace' => $this->workspace->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['asset' => ['uuid', 'name', 'discovery_confidence'], 'hardware', 'lifecycle', 'ai_explanation']]);

        $this->assertSame('app-01', $response->json('data.asset.name'));
    }

    public function test_license_review_is_honest_about_missing_data(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/qynasset/intelligence/licenses/review', [
                'workspace' => $this->workspace->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['licenses' => ['available' => false]]]);
    }

    public function test_non_member_is_forbidden(): void
    {
        $other = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-asset@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($other)
            ->getJson('/api/qynasset/intelligence/overview?workspace='.$this->workspace->uuid)
            ->assertStatus(403);
    }

    public function test_workspace_uuid_is_required(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/qynasset/intelligence/overview')
            ->assertStatus(422);
    }
}

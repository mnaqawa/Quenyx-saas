<?php

namespace Tests\Feature;

use App\Models\ObserveAlertEvent;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Models\User;
use App\Support\Observe\OperationsEntityId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 21 — Operations Intelligence. Validates the full pipeline in safe (mock) mode: DI wiring,
 * UUID-only + workspace-scoped + RBAC envelope, deterministic evidence, and AI narration reuse.
 */
class OperationsIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $workspace;

    private int $alertId;

    private int $hostId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Ops User',
            'email' => 'ops@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->workspace = Project::create([
            'owner_id' => $this->user->id,
            'name' => 'Ops Workspace',
            'status' => 'active',
        ]);

        $prefix = 'ws'.$this->workspace->id.'-';

        $host = ObserveTargetHost::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'app-01',
            'address' => '10.0.0.5',
            'source' => 'manual',
            'check_command' => 'check-host-alive',
            'enabled' => true,
        ]);
        $this->hostId = (int) $host->id;

        ObserveService::create([
            'workspace_id' => $this->workspace->id,
            'engine_key' => 'native',
            'engine_service_key' => $prefix.'app-01::cpu',
            'host_name' => $prefix.'app-01',
            'service_name' => 'cpu',
            'state' => 'critical',
            'last_check_at' => now(),
            'last_state_change_at' => now()->subMinutes(10),
            'output' => 'CRITICAL - CPU 96%',
        ]);

        // Real metric history so deterministic root-cause/forecast have evidence.
        for ($i = 12; $i >= 0; $i--) {
            ObserveMetricHistory::insert([
                'workspace_id' => $this->workspace->id,
                'host_name' => $prefix.'app-01',
                'service_name' => 'cpu',
                'metric' => 'cpu',
                'value' => 70 + (12 - $i) * 2, // rising toward saturation
                'recorded_at' => now()->subHours($i),
            ]);
        }

        $alert = ObserveAlertEvent::create([
            'workspace_id' => $this->workspace->id,
            'target_host_id' => $host->id,
            'host_name' => $prefix.'app-01',
            'service_name' => 'cpu',
            'severity' => 'critical',
            'title' => 'High CPU on app-01',
            'message' => 'CPU exceeded 90% for 5 minutes',
            'status' => 'open',
            'triggered_at' => now()->subMinutes(15),
            'opened_at' => now()->subMinutes(15),
            'last_seen_at' => now(),
            'occurrence_count' => 3,
        ]);
        $this->alertId = (int) $alert->id;
    }

    public function test_overview_returns_real_operational_intelligence(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynsight/intelligence/overview?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'infrastructure_health' => ['hosts_total', 'service_state_counts', 'unhealthy_hosts'],
                    'open_alerts',
                    'critical_services',
                    'top_operational_risks',
                    'predicted_capacity_risks',
                    'recent_recommendations',
                    'recent_ai_investigations',
                ],
            ]);

        $this->assertSame(1, $response->json('data.infrastructure_health.hosts_total'));
    }

    public function test_copilot_reuses_quenyx_ai_conversation_in_mock_mode(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynsight/intelligence/copilot', [
            'workspace' => $this->workspace->uuid,
            'message' => 'Why is app-01 under high load?',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['conversation_uuid', 'message_uuid', 'answer' => ['mocked', 'ai_enabled'], 'evidence']]);

        $this->assertTrue($response->json('data.answer.mocked'));
        $this->assertFalse($response->json('data.answer.ai_enabled'));
    }

    public function test_alert_explain_returns_deterministic_evidence_and_narration(): void
    {
        $uuid = OperationsEntityId::for(OperationsEntityId::TYPE_ALERT, $this->workspace->id, $this->alertId);

        $response = $this->actingAs($this->user)
            ->postJson("/api/qynsight/intelligence/alerts/{$uuid}/explain", [
                'workspace' => $this->workspace->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['operational_impact', 'most_likely_causes', 'evidence_used', 'suggested_actions', 'root_cause', 'ai_explanation'],
            ]);

        // Deterministic root cause must point at the CPU layer given the evidence.
        $this->assertSame('cpu', $response->json('data.root_cause.layer'));
    }

    public function test_host_capacity_prediction_is_uuid_scoped(): void
    {
        $uuid = OperationsEntityId::for(OperationsEntityId::TYPE_HOST, $this->workspace->id, $this->hostId);

        $response = $this->actingAs($this->user)
            ->postJson("/api/qynsight/intelligence/capacity/{$uuid}/predict", [
                'workspace' => $this->workspace->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['scope', 'host_forecast' => ['cpu'], 'ai_explanation']]);
    }

    public function test_non_member_is_forbidden(): void
    {
        $other = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($other)
            ->getJson('/api/qynsight/intelligence/overview?workspace='.$this->workspace->uuid)
            ->assertStatus(403);
    }

    public function test_workspace_uuid_is_required(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/qynsight/intelligence/overview')
            ->assertStatus(422);
    }
}

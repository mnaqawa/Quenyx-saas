<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\Context\EnterpriseContextEngine;
use App\Services\Platform\EventBus\PlatformEventBus;
use App\Services\Platform\EventBus\PlatformEventNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 25 — Enterprise Intelligence Platform v1.0. Validates the Platform Event Bus, the Enterprise
 * Context Engine, QynVA (Enterprise AI Operator), QynBalance (Cost Intelligence), Executive Intelligence,
 * Enterprise Analytics, and Platform Health — all under the UUID-only + workspace-scoped + RBAC envelope,
 * evidence-based and honest (no fabricated values; pricing reported unavailable when unset).
 */
class EnterpriseIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // A free plan that grants the Sprint 25 modules (self-contained; no seeder dependency).
        Plan::create([
            'key' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'features' => [
                'modules_allowed' => ['qynva', 'qynbalance', 'qynknow', 'qynsight'],
                'limits' => [],
            ],
        ]);

        $this->user = User::create([
            'name' => 'Operator User',
            'email' => 'operator@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->workspace = Project::create([
            'owner_id' => $this->user->id,
            'name' => 'Enterprise Workspace',
            'status' => 'active',
        ]);
    }

    public function test_event_bus_has_full_vocabulary_and_subscriber(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynva/events?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame(count(PlatformEventNames::all()), $response->json('data.bus.event_count'));
        $keys = array_column($response->json('data.bus.subscribers'), 'key');
        $this->assertContains('qynnotify.fanout', $keys);
    }

    public function test_event_bus_publish_is_workspace_aware_and_recorded(): void
    {
        /** @var PlatformEventBus $bus */
        $bus = app(PlatformEventBus::class);
        $event = $bus->publish(PlatformEventNames::CONVERSATION_COMPLETED, $this->workspace, $this->user, ['title' => 'test']);

        $this->assertSame((string) $this->workspace->uuid, $event->workspaceUuid);
        $this->assertNotEmpty($event->uuid);
        $this->assertNotEmpty($bus->recent(5));
    }

    public function test_unknown_event_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(PlatformEventBus::class)->publish('NotARealEvent', $this->workspace, $this->user);
    }

    public function test_context_engine_returns_one_normalized_object(): void
    {
        /** @var EnterpriseContextEngine $engine */
        $engine = app(EnterpriseContextEngine::class);
        $context = $engine->build($this->workspace, $this->user, ['query' => 'cpu']);

        $this->assertArrayHasKey('workspace', $context);
        $this->assertArrayHasKey('cross_module', $context);
        $this->assertArrayHasKey('timeline', $context);
        $this->assertArrayHasKey('graph', $context);
        $this->assertArrayHasKey('summary', $context);
        $this->assertSame((string) $this->workspace->uuid, $context['workspace']['uuid']);
    }

    public function test_qynva_operator_discovers_capabilities(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynva/operator/capabilities?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertGreaterThanOrEqual(1, $response->json('data.module_count'));
        $this->assertNotEmpty($response->json('data.actions'));
    }

    public function test_qynva_operator_operate_is_mocked_and_never_executes(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynva/operator/operate', [
            'workspace' => $this->workspace->uuid,
            'message' => 'What should we focus on right now?',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['conversation_uuid', 'answer' => ['ai_enabled'], 'available_actions', 'note']]);
        $this->assertFalse($response->json('data.answer.ai_enabled'));
    }

    public function test_executive_dashboard_is_evidence_based(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynva/executive?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['operational_health' => ['score', 'status'], 'top_risks', 'top_recommendations', 'incident_kpis']]);
    }

    public function test_executive_summary_is_mocked(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynva/executive/summary', [
            'workspace' => $this->workspace->uuid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['dashboard', 'executive_summary' => ['ai_enabled']]]);
        $this->assertFalse($response->json('data.executive_summary.ai_enabled'));
    }

    public function test_analytics_reports_honestly_when_data_is_missing(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynva/analytics?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['mttd' => ['available'], 'mttr' => ['incident', 'alert'], 'executive_kpis']]);
        // No incidents yet → MTTR incident unavailable rather than a fabricated number.
        $this->assertFalse($response->json('data.mttr.incident.available'));
    }

    public function test_platform_health_snapshot_includes_event_bus(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynva/health?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['overall_status', 'areas' => ['ai_platform', 'event_bus', 'registries', 'queues']]]);
        $this->assertGreaterThanOrEqual(1, $response->json('data.areas.event_bus.subscriber_count'));
    }

    public function test_qynbalance_reports_counts_and_pricing_unavailable(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynbalance/cost/overview?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['currency', 'pricing_configured', 'infrastructure' => ['lines', 'pricing_available'], 'recommendations']]);
        // No rates configured by default → no fabricated financials.
        $this->assertFalse($response->json('data.pricing_configured'));
        $this->assertNull($response->json('data.infrastructure.estimated_monthly_total'));
        $keys = array_column($response->json('data.recommendations'), 'key');
        $this->assertContains('configure_pricing', $keys);
    }

    public function test_qynbalance_cost_copilot_is_mocked(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynbalance/cost/copilot', [
            'workspace' => $this->workspace->uuid,
            'message' => 'Where can we reduce cost?',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['conversation_uuid', 'answer' => ['ai_enabled']]]);
        $this->assertFalse($response->json('data.answer.ai_enabled'));
    }

    public function test_workspace_uuid_is_required(): void
    {
        $this->actingAs($this->user)->getJson('/api/qynva/executive')->assertStatus(422);
    }

    public function test_non_member_is_forbidden(): void
    {
        $other = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-intel@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($other)
            ->getJson('/api/qynva/executive?workspace='.$this->workspace->uuid)
            ->assertStatus(403);
    }
}

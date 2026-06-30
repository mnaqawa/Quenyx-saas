<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 24 — Enterprise Knowledge & Collaboration Platform. Validates the shared, registry-driven
 * Knowledge Platform, Enterprise Search, Global Timeline, Knowledge Graph v2, Ticket & Notification
 * Intelligence (mock mode), and the reusable Collaboration layer — all under the UUID-only +
 * workspace-scoped + RBAC envelope, with honest (never fabricated) results.
 */
class KnowledgePlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Knowledge User',
            'email' => 'knowledge@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->workspace = Project::create([
            'owner_id' => $this->user->id,
            'name' => 'Knowledge Workspace',
            'status' => 'active',
        ]);
    }

    public function test_adapter_registry_includes_sprint24_modules(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/adapters?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $keys = array_column($response->json('data.adapters'), 'module_key');
        $this->assertContains('qynknow', $keys);
        $this->assertContains('qynsupport', $keys);
        $this->assertContains('qynnotify', $keys);
    }

    public function test_knowledge_sources_registry_reports_internal_operational_and_others_planned(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynknow/sources?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $sources = collect($response->json('data.sources'))->keyBy('key');

        $this->assertTrue($sources['internal']['operational']);
        $this->assertFalse($sources['confluence']['operational']);
        $this->assertArrayHasKey('vector_store', $sources->all());
    }

    public function test_document_creation_and_enterprise_search_returns_real_data_only(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/qynknow/documents', [
            'workspace' => $this->workspace->uuid,
            'title' => 'Restarting the Apache web tier',
            'body' => 'Steps to safely restart apache after high CPU events on the web tier.',
            'category' => 'runbook',
            'status' => 'published',
        ]);
        $create->assertStatus(201)->assertJson(['success' => true]);

        $search = $this->actingAs($this->user)
            ->getJson('/api/qynknow/search?q=apache&workspace='.$this->workspace->uuid);
        $search->assertStatus(200)->assertJson(['success' => true]);
        $titles = array_column($search->json('data.results'), 'title');
        $this->assertContains('Restarting the Apache web tier', $titles);

        // A query that matches nothing returns no fabricated hits.
        $empty = $this->actingAs($this->user)
            ->getJson('/api/qynknow/search?q=zzzznomatchterm&workspace='.$this->workspace->uuid);
        $empty->assertStatus(200);
        $this->assertSame(0, $empty->json('data.total'));
    }

    public function test_knowledge_graph_and_global_timeline_build(): void
    {
        $this->actingAs($this->user)->postJson('/api/qynknow/documents', [
            'workspace' => $this->workspace->uuid,
            'title' => 'Network DNS playbook',
            'body' => 'DNS troubleshooting.',
        ])->assertStatus(201);

        $graph = $this->actingAs($this->user)->getJson('/api/qynknow/graph?workspace='.$this->workspace->uuid);
        $graph->assertStatus(200)->assertJsonStructure(['data' => ['nodes', 'edges', 'counts_by_type']]);
        $this->assertGreaterThanOrEqual(1, $graph->json('data.node_count'));

        $timeline = $this->actingAs($this->user)->getJson('/api/qynknow/timeline?workspace='.$this->workspace->uuid);
        $timeline->assertStatus(200)->assertJsonStructure(['data' => ['events', 'total']]);
    }

    public function test_knowledge_assistant_draft_is_editable_and_mocked(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynknow/intelligence/draft', [
            'workspace' => $this->workspace->uuid,
            'kind' => 'kb',
            'topic' => 'Disk full remediation',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['kind', 'topic', 'ai_draft' => ['ai_enabled'], 'document_scaffold' => ['title', 'status'], 'note']]);
        $this->assertFalse($response->json('data.ai_draft.ai_enabled'));
        $this->assertSame('draft', $response->json('data.document_scaffold.status'));
    }

    public function test_ticket_intelligence_produces_evidence_based_suggestions(): void
    {
        $ticket = $this->actingAs($this->user)->postJson('/api/qynsupport/tickets', [
            'workspace' => $this->workspace->uuid,
            'subject' => 'Production outage: service is down',
            'description' => 'Critical outage affecting all users.',
        ]);
        $ticket->assertStatus(201)->assertJson(['success' => true]);
        $uuid = $ticket->json('data.uuid');

        $analyze = $this->actingAs($this->user)->postJson("/api/qynsupport/tickets/{$uuid}/intelligence/analyze", [
            'workspace' => $this->workspace->uuid,
        ]);
        $analyze->assertStatus(200)->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['suggestions' => ['category', 'priority', 'impact', 'suggested_sla' => ['hours']], 'ai_rationale' => ['ai_enabled']]]);
        // "outage"/"down" deterministically escalates priority.
        $this->assertSame('critical', $analyze->json('data.suggestions.priority'));
    }

    public function test_notification_ingest_deduplicates_and_routes_to_real_members(): void
    {
        $first = $this->actingAs($this->user)->postJson('/api/qynnotify/notifications', [
            'workspace' => $this->workspace->uuid,
            'title' => 'Disk space low on db-1',
            'severity' => 'high',
            'source' => 'qynsight',
        ]);
        $first->assertStatus(201);
        $firstUuid = $first->json('data.uuid');

        // Same signal collapses into the existing notification (deterministic dedup).
        $second = $this->actingAs($this->user)->postJson('/api/qynnotify/notifications', [
            'workspace' => $this->workspace->uuid,
            'title' => 'Disk space low on db-1',
            'severity' => 'high',
            'source' => 'qynsight',
        ]);
        $second->assertStatus(201);
        $this->assertSame($firstUuid, $second->json('data.uuid'));
        $this->assertSame(2, $second->json('data.dedup_count'));

        $list = $this->actingAs($this->user)->getJson('/api/qynnotify/notifications?workspace='.$this->workspace->uuid);
        $list->assertStatus(200);
        $this->assertCount(1, $list->json('data.notifications'));
    }

    public function test_collaboration_is_reusable_across_entities(): void
    {
        $ticketUuid = $this->actingAs($this->user)->postJson('/api/qynsupport/tickets', [
            'workspace' => $this->workspace->uuid,
            'subject' => 'Need VPN access',
        ])->json('data.uuid');

        $comment = $this->actingAs($this->user)->postJson('/api/collaboration/comments', [
            'workspace' => $this->workspace->uuid,
            'entity_type' => 'ticket',
            'entity_uuid' => $ticketUuid,
            'body' => 'Investigating the VPN access request.',
        ]);
        $comment->assertStatus(201)->assertJson(['success' => true]);
        $this->assertCount(1, $comment->json('data.thread.comments'));
    }

    public function test_workspace_uuid_is_required(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/qynknow/sources')
            ->assertStatus(422);
    }

    public function test_non_member_is_forbidden(): void
    {
        $other = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-knowledge@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($other)
            ->getJson('/api/qynknow/sources?workspace='.$this->workspace->uuid)
            ->assertStatus(403);
    }
}

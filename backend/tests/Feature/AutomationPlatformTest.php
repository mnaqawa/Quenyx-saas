<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 23 — Enterprise Automation & Incident Intelligence. Validates the shared, registry-driven
 * Automation Platform and the QynReact Incident Workspace in safe (mock + dry-run) mode: dynamic
 * adapter discovery, safe-by-default execution, the approval gate for live actions, rollback rules,
 * cross-module orchestration (no branching), and the UUID-only + workspace-scoped + RBAC envelope.
 */
class AutomationPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Automation User',
            'email' => 'automation@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->workspace = Project::create([
            'owner_id' => $this->user->id,
            'name' => 'Automation Workspace',
            'status' => 'active',
        ]);
    }

    public function test_adapter_registry_includes_qynrun_and_qynreact(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/adapters?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $keys = array_column($response->json('data.adapters'), 'module_key');
        $this->assertContains('qynrun', $keys);
        $this->assertContains('qynreact', $keys);
    }

    public function test_automation_adapters_are_discoverable_and_safe_by_default(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/qynrun/automation/adapters?workspace='.$this->workspace->uuid);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['live_execution_enabled' => false]]);

        $keys = array_column($response->json('data.adapters'), 'key');
        foreach (['ssh', 'powershell', 'rest', 'webhook', 'script', 'kubernetes', 'aws'] as $expected) {
            $this->assertContains($expected, $keys, "adapter {$expected} should be registered");
        }
    }

    public function test_dispatch_defaults_to_dry_run_and_performs_no_side_effect(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynrun/automation/executions', [
            'workspace' => $this->workspace->uuid,
            'adapter_key' => 'rest',
            'action_key' => 'http_request',
            'parameters' => ['method' => 'GET', 'url' => 'https://example.com/health'],
            'mode' => 'dry_run',
        ]);

        $response->assertStatus(201)->assertJson(['success' => true, 'data' => ['status' => 'dry_run']]);
        $this->assertSame('dry_run', $response->json('data.result.status'));
    }

    public function test_live_execution_requires_approval_and_stays_safe_without_runner(): void
    {
        config(['automation.live_execution' => true]);

        $dispatch = $this->actingAs($this->user)->postJson('/api/qynrun/automation/executions', [
            'workspace' => $this->workspace->uuid,
            'adapter_key' => 'rest',
            'action_key' => 'http_request',
            'parameters' => ['method' => 'POST', 'url' => 'https://example.com/restart'],
            'mode' => 'live',
        ]);

        $dispatch->assertStatus(201)->assertJson(['success' => true, 'data' => ['status' => 'awaiting_approval']]);
        $executionUuid = $dispatch->json('data.uuid');

        $approvals = $this->actingAs($this->user)
            ->getJson('/api/qynrun/automation/approvals?workspace='.$this->workspace->uuid);
        $approvals->assertStatus(200);
        $approvalUuid = $approvals->json('data.approvals.0.uuid');
        $this->assertNotNull($approvalUuid);

        // Approving runs it — but with no allowlisted host the adapter stays in a safe dry-run plan.
        $decide = $this->actingAs($this->user)->postJson("/api/qynrun/automation/approvals/{$approvalUuid}/decide", [
            'workspace' => $this->workspace->uuid,
            'approve' => true,
        ]);
        $decide->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame($executionUuid, $decide->json('data.uuid'));
        $this->assertContains($decide->json('data.status'), ['dry_run', 'skipped']);
    }

    public function test_rollback_rejects_non_succeeded_execution(): void
    {
        $dispatch = $this->actingAs($this->user)->postJson('/api/qynrun/automation/executions', [
            'workspace' => $this->workspace->uuid,
            'adapter_key' => 'ssh',
            'action_key' => 'restart_service_linux',
            'parameters' => ['host' => 'h1', 'command' => 'systemctl restart nginx'],
            'mode' => 'dry_run',
        ]);
        $uuid = $dispatch->json('data.uuid');

        $this->actingAs($this->user)->postJson("/api/qynrun/automation/executions/{$uuid}/rollback", [
            'workspace' => $this->workspace->uuid,
        ])->assertStatus(422);
    }

    public function test_runbook_suggestion_is_editable_draft_in_mock_mode(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/qynrun/intelligence/runbooks/suggest', [
            'workspace' => $this->workspace->uuid,
            'problem' => 'High CPU on web tier',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['problem', 'suggested_runbook' => ['definition' => ['steps']], 'ai_rationale' => ['ai_enabled'], 'note']]);
        $this->assertFalse($response->json('data.ai_rationale.ai_enabled'));
    }

    public function test_incident_workspace_reuses_cross_module_intelligence(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/qynreact/incidents', [
            'workspace' => $this->workspace->uuid,
            'title' => 'API latency spike',
            'severity' => 'high',
        ]);
        $create->assertStatus(201)->assertJson(['success' => true]);
        $uuid = $create->json('data.uuid');

        $workspace = $this->actingAs($this->user)
            ->getJson("/api/qynreact/incidents/{$uuid}?workspace=".$this->workspace->uuid);

        $workspace->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['knowledge' => ['available' => false]]])
            ->assertJsonStructure(['data' => ['incident' => ['uuid'], 'timeline', 'cross_module' => ['modules', 'module_count'], 'automation']]);

        // Cross-module gather must exclude QynReact itself (recursion guard).
        $modules = array_column($workspace->json('data.cross_module.modules'), 'module');
        $this->assertNotContains('qynreact', $modules);
    }

    public function test_incident_copilot_runs_in_mock_mode(): void
    {
        $uuid = $this->actingAs($this->user)->postJson('/api/qynreact/incidents', [
            'workspace' => $this->workspace->uuid,
            'title' => 'DB connection errors',
        ])->json('data.uuid');

        $this->actingAs($this->user)->postJson("/api/qynreact/incidents/{$uuid}/copilot", [
            'workspace' => $this->workspace->uuid,
            'message' => 'What changed before this incident?',
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['answer' => ['mocked' => true, 'ai_enabled' => false]]]);
    }

    public function test_non_member_is_forbidden(): void
    {
        $other = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-automation@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($other)
            ->getJson('/api/qynrun/automation/adapters?workspace='.$this->workspace->uuid)
            ->assertStatus(403);
    }

    public function test_workspace_uuid_is_required(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/qynrun/automation/adapters')
            ->assertStatus(422);
    }
}

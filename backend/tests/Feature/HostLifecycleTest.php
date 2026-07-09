<?php

namespace Tests\Feature;

use App\Constants\AgentConstants;
use App\Constants\HostLifecycleStatus;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Services\PlatformAgent\AgentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HostLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function seedAndUser(): array
    {
        $this->artisan('db:seed', ['--class' => 'PlanSeeder']);
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

    public function test_deleting_agent_marks_linked_host_agent_removed(): void
    {
        [$user, $project] = $this->seedAndUser();

        $agent = Agent::create([
            'id' => Agent::generateId(),
            'workspace_id' => $project->id,
            'hostname' => 'host-a',
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            'agent_secret_hash' => Hash::make('secret'),
            'status' => AgentConstants::STATUS_ONLINE,
            'enrolled_at' => now(),
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'host-a',
            'address' => '10.0.0.1',
            'agent_id' => $agent->id,
            'source' => 'agent',
            'enabled' => true,
            'lifecycle_status' => HostLifecycleStatus::ACTIVE,
        ]);

        ObserveTargetService::create([
            'workspace_id' => $project->id,
            'host_id' => $host->id,
            'name' => 'cpu',
            'service_key' => 'cpu',
            'check_command' => 'platform_agent_telemetry',
            'check_source' => AgentConstants::CHECK_SOURCE_PLATFORM_AGENT,
            'enabled' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->deleteJson("/api/workspaces/{$project->id}/agents/{$agent->id}")
            ->assertStatus(200);

        $host->refresh();
        $this->assertSame(HostLifecycleStatus::AGENT_REMOVED, $host->lifecycle_status);
        $this->assertNull($host->agent_id);
        $this->assertFalse((bool) ObserveTargetService::where('host_id', $host->id)->where('enabled', true)->exists());
    }

    public function test_suspend_host_via_api(): void
    {
        [$user, $project] = $this->seedAndUser();

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'srv1',
            'address' => '10.0.0.2',
            'enabled' => true,
            'lifecycle_status' => HostLifecycleStatus::ACTIVE,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/workspaces/{$project->id}/qynsight/hosts/{$host->uuid}/suspend")
            ->assertStatus(200)
            ->assertJsonPath('data.lifecycle_status', HostLifecycleStatus::SUSPENDED);

        $this->assertFalse($host->fresh()->isMonitoringAllowed());
    }

    public function test_archive_host_hidden_from_default_list(): void
    {
        [$user, $project] = $this->seedAndUser();

        ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'archived-host',
            'address' => '10.0.0.3',
            'enabled' => false,
            'lifecycle_status' => HostLifecycleStatus::ARCHIVED,
        ]);

        Sanctum::actingAs($user, ['*']);
        $res = $this->getJson("/api/workspaces/{$project->id}/observe/targets?lifecycle=default");
        $res->assertStatus(200);
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertNotContains('archived-host', $names);
    }

    public function test_restore_archived_host(): void
    {
        [$user, $project] = $this->seedAndUser();

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'restore-me',
            'address' => '10.0.0.4',
            'enabled' => false,
            'lifecycle_status' => HostLifecycleStatus::ARCHIVED,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/workspaces/{$project->id}/qynsight/hosts/{$host->uuid}/restore")
            ->assertStatus(200)
            ->assertJsonPath('data.lifecycle_status', HostLifecycleStatus::ACTIVE);
    }

    public function test_member_cannot_suspend_host(): void
    {
        [$user, $project] = $this->seedAndUser();
        $member = User::create([
            'name' => 'Member',
            'email' => 'member@test.com',
            'password' => Hash::make('password'),
        ]);
        \App\Models\ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'protected',
            'address' => '10.0.0.5',
            'lifecycle_status' => HostLifecycleStatus::ACTIVE,
        ]);

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/workspaces/{$project->id}/qynsight/hosts/{$host->uuid}/suspend")
            ->assertStatus(403);
    }
}

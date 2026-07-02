<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectIntegrationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'test@example.com'): User
    {
        return User::create([
            'name' => 'Test',
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);
    }

    public function test_free_plan_owner_can_list_integrations(): void
    {
        $this->artisan('db:seed', ['--class' => 'PlanSeeder']);
        $this->artisan('db:seed', ['--class' => 'ModuleSeeder']);

        $user = $this->makeUser();
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'WS',
            'status' => 'active',
        ]);

        $this->artisan('db:seed', ['--class' => 'IntegrationSeeder']);

        Sanctum::actingAs($user, ['*']);

        $res = $this->getJson("/api/projects/{$project->id}/integrations");

        $res->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_pro_plan_member_cannot_upsert_integration_configuration(): void
    {
        $this->artisan('db:seed', ['--class' => 'PlanSeeder']);
        $this->artisan('db:seed', ['--class' => 'ModuleSeeder']);
        $this->artisan('db:seed', ['--class' => 'IntegrationSeeder']);

        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');

        $project = Project::create([
            'owner_id' => $owner->id,
            'name' => 'WS',
            'status' => 'active',
        ]);

        ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $pro = Plan::where('key', 'pro')->first();
        $this->assertNotNull($pro);
        ProjectSubscription::create([
            'project_id' => $project->id,
            'plan_id' => $pro->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
        ]);

        $integration = Integration::query()->orderBy('id')->first();
        $this->assertNotNull($integration);

        Sanctum::actingAs($member, ['*']);

        $res = $this->putJson(
            "/api/projects/{$project->id}/integrations/{$integration->id}/configuration",
            ['settings' => ['x' => 'y']]
        );

        $res->assertStatus(403);
    }

    public function test_pro_plan_admin_can_upsert_integration_configuration(): void
    {
        $this->artisan('db:seed', ['--class' => 'PlanSeeder']);
        $this->artisan('db:seed', ['--class' => 'ModuleSeeder']);
        $this->artisan('db:seed', ['--class' => 'IntegrationSeeder']);

        $owner = $this->makeUser('owner2@example.com');
        $admin = $this->makeUser('admin@example.com');

        $project = Project::create([
            'owner_id' => $owner->id,
            'name' => 'WS2',
            'status' => 'active',
        ]);

        ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'role' => 'admin',
        ]);

        $pro = Plan::where('key', 'pro')->first();
        ProjectSubscription::create([
            'project_id' => $project->id,
            'plan_id' => $pro->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
        ]);

        $integration = Integration::query()->orderBy('id')->first();
        $this->assertNotNull($integration);

        Sanctum::actingAs($admin, ['*']);

        $res = $this->putJson(
            "/api/projects/{$project->id}/integrations/{$integration->id}/configuration",
            ['settings' => ['key' => 'val']]
        );

        $res->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}

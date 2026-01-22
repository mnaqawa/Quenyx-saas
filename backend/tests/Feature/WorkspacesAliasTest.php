<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspacesAliasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to authenticate a user with Sanctum
     */
    protected function actingAsUser(User $user): self
    {
        Sanctum::actingAs($user, ['*']);
        return $this;
    }

    /**
     * Helper to create a user
     */
    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'test' . uniqid() . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ], $attributes));
    }

    /**
     * Test that GET /api/workspaces returns same data as GET /api/projects
     */
    public function test_workspaces_index_returns_same_data_as_projects(): void
    {
        $user = $this->createUser();

        // Create a project where user is owner
        $project1 = Project::create([
            'owner_id' => $user->id,
            'name' => 'Test Project 1',
            'status' => 'active',
        ]);

        // Create a project where user is member
        $otherUser = $this->createUser(['name' => 'Other User', 'email' => 'other@example.com']);
        $project2 = Project::create([
            'owner_id' => $otherUser->id,
            'name' => 'Test Project 2',
            'status' => 'active',
        ]);

        ProjectMembership::create([
            'project_id' => $project2->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        // Test /api/projects endpoint
        $projectsResponse = $this->actingAsUser($user)
            ->getJson('/api/projects');

        $projectsResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'project' => ['id', 'name', 'status'],
                        'my_role',
                    ],
                ],
            ]);

        // Test /api/workspaces endpoint (alias)
        $workspacesResponse = $this->actingAsUser($user)
            ->getJson('/api/workspaces');

        $workspacesResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'project' => ['id', 'name', 'status'],
                        'my_role',
                    ],
                ],
            ]);

        // Verify both responses contain the same data
        $projectsData = $projectsResponse->json('data');
        $workspacesData = $workspacesResponse->json('data');

        $this->assertCount(2, $projectsData);
        $this->assertCount(2, $workspacesData);

        // Sort by project id for comparison
        usort($projectsData, fn($a, $b) => $a['project']['id'] <=> $b['project']['id']);
        usort($workspacesData, fn($a, $b) => $a['project']['id'] <=> $b['project']['id']);

        $this->assertEquals($projectsData, $workspacesData);
    }

    /**
     * Test that GET /api/workspaces/{project}/memberships returns same data as /api/projects/{project}/memberships
     */
    public function test_workspaces_memberships_index_returns_same_data(): void
    {
        $owner = $this->createUser(['name' => 'Owner User', 'email' => 'owner@example.com']);
        $member = $this->createUser(['name' => 'Member User', 'email' => 'member@example.com']);

        $project = Project::create([
            'owner_id' => $owner->id,
            'name' => 'Test Project',
            'status' => 'active',
        ]);

        ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        // Test /api/projects/{project}/memberships endpoint
        $projectsResponse = $this->actingAsUser($owner)
            ->getJson("/api/projects/{$project->id}/memberships");

        $projectsResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'memberships' => [
                        '*' => ['id', 'user_id', 'user', 'role'],
                    ],
                    'invites',
                ],
            ]);

        // Test /api/workspaces/{project}/memberships endpoint (alias)
        $workspacesResponse = $this->actingAsUser($owner)
            ->getJson("/api/workspaces/{$project->id}/memberships");

        $workspacesResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'memberships' => [
                        '*' => ['id', 'user_id', 'user', 'role'],
                    ],
                    'invites',
                ],
            ]);

        // Verify both responses contain the same data
        $projectsData = $projectsResponse->json('data');
        $workspacesData = $workspacesResponse->json('data');

        $this->assertEquals($projectsData, $workspacesData);
    }

    /**
     * Test that workspaces alias requires authentication
     */
    public function test_workspaces_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/workspaces');

        $response->assertStatus(401);
    }

    /**
     * Test that workspaces memberships alias requires authentication
     */
    public function test_workspaces_memberships_requires_authentication(): void
    {
        $user = $this->createUser();
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'Test Project',
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/workspaces/{$project->id}/memberships");

        $response->assertStatus(401);
    }
}

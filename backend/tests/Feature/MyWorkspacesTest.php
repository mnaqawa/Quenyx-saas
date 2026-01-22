<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyWorkspacesTest extends TestCase
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
     * Test GET /api/projects returns only member projects and includes my_role
     */
    public function test_get_projects_returns_member_projects_with_my_role(): void
    {
        $user = $this->createUser();

        // Create project where user is owner
        $ownedProject = Project::create([
            'owner_id' => $user->id,
            'name' => 'My Owned Project',
            'status' => 'active',
        ]);

        // Create another project where user is a member
        $otherOwner = $this->createUser();
        $memberProject = Project::create([
            'owner_id' => $otherOwner->id,
            'name' => 'Project I Am Member Of',
            'status' => 'active',
        ]);

        ProjectMembership::create([
            'project_id' => $memberProject->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);

        // Create a project user is NOT a member of
        $unrelatedProject = Project::create([
            'owner_id' => $otherOwner->id,
            'name' => 'Unrelated Project',
            'status' => 'active',
        ]);

        $response = $this->actingAsUser($user)
            ->getJson('/api/projects');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'project' => [
                            'id',
                            'name',
                            'status',
                            'created_at',
                            'updated_at',
                        ],
                        'my_role',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data, 'Should return exactly 2 projects');

        // Verify owned project has role 'owner'
        $ownedProjectData = collect($data)->firstWhere('project.id', $ownedProject->id);
        $this->assertNotNull($ownedProjectData);
        $this->assertEquals('owner', $ownedProjectData['my_role']);
        $this->assertEquals('My Owned Project', $ownedProjectData['project']['name']);

        // Verify member project has role 'admin'
        $memberProjectData = collect($data)->firstWhere('project.id', $memberProject->id);
        $this->assertNotNull($memberProjectData);
        $this->assertEquals('admin', $memberProjectData['my_role']);
        $this->assertEquals('Project I Am Member Of', $memberProjectData['project']['name']);

        // Verify unrelated project is NOT included
        $unrelatedProjectData = collect($data)->firstWhere('project.id', $unrelatedProject->id);
        $this->assertNull($unrelatedProjectData);
    }

    /**
     * Test GET /api/projects returns empty array when user has no projects
     */
    public function test_get_projects_returns_empty_when_user_has_no_projects(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsUser($user)
            ->getJson('/api/projects');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [],
            ]);
    }

    /**
     * Test GET /api/projects deduplicates when user is both owner and member
     */
    public function test_get_projects_deduplicates_owner_and_member(): void
    {
        $user = $this->createUser();

        // Create project where user is owner
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'My Project',
            'status' => 'active',
        ]);

        // Also create a membership (edge case: user is both owner and has membership record)
        ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);

        $response = $this->actingAsUser($user)
            ->getJson('/api/projects');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data, 'Should return project only once');

        // Should prefer owner role
        $projectData = $data[0];
        $this->assertEquals('owner', $projectData['my_role']);
    }

    /**
     * Test GET /api/projects requires authentication
     */
    public function test_get_projects_requires_authentication(): void
    {
        $response = $this->getJson('/api/projects');

        $response->assertStatus(401);
    }
}

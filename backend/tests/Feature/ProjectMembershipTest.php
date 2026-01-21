<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectMembershipTest extends TestCase
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
     * Helper to create a project with owner
     */
    protected function createProjectWithOwner(User $owner, string $name = 'Test Project'): Project
    {
        return Project::create([
            'owner_id' => $owner->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    /**
     * Helper to create a membership
     */
    protected function createMembership(Project $project, User $user, string $role): ProjectMembership
    {
        return ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    // ==================== Membership List Authorization ====================

    public function test_owner_can_view_memberships(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $member, 'member');

        $response = $this->actingAsUser($owner)
            ->getJson("/api/projects/{$project->id}/memberships");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'memberships' => [
                        '*' => [
                            'id',
                            'user_id',
                            'user' => ['id', 'name', 'email'],
                            'role',
                            'created_at',
                        ],
                    ],
                    'invites' => [],
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_admin_can_view_memberships(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $admin, 'admin');

        $response = $this->actingAsUser($admin)
            ->getJson("/api/projects/{$project->id}/memberships");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_member_cannot_view_memberships(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $member, 'member');

        $response = $this->actingAsUser($member)
            ->getJson("/api/projects/{$project->id}/memberships");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_viewer_cannot_view_memberships(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $viewer, 'viewer');

        $response = $this->actingAsUser($viewer)
            ->getJson("/api/projects/{$project->id}/memberships");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_non_member_cannot_view_memberships(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $nonMember = User::create([
            'name' => 'Non Member',
            'email' => 'nonmember@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAsUser($nonMember)
            ->getJson("/api/projects/{$project->id}/memberships");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    // ==================== Role Changes ====================

    public function test_owner_can_promote_admin_to_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $membership = $this->createMembership($project, $admin, 'admin');

        $response = $this->actingAsUser($owner)
            ->putJson("/api/projects/{$project->id}/memberships/{$membership->id}", [
                'role' => 'owner',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'role' => 'owner',
                ],
            ]);

        $this->assertDatabaseHas('project_memberships', [
            'id' => $membership->id,
            'role' => 'owner',
        ]);
    }

    public function test_admin_cannot_promote_to_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $admin, 'admin');

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $membership = $this->createMembership($project, $member, 'member');

        $response = $this->actingAsUser($admin)
            ->putJson("/api/projects/{$project->id}/memberships/{$membership->id}", [
                'role' => 'owner',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Verify role unchanged
        $this->assertDatabaseHas('project_memberships', [
            'id' => $membership->id,
            'role' => 'member',
        ]);
    }

    public function test_admin_cannot_change_role_of_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        // Create a second owner via membership
        $secondOwner = User::create([
            'name' => 'Second Owner',
            'email' => 'secondowner@example.com',
            'password' => Hash::make('password'),
        ]);

        $ownerMembership = $this->createMembership($project, $secondOwner, 'owner');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $admin, 'admin');

        $response = $this->actingAsUser($admin)
            ->putJson("/api/projects/{$project->id}/memberships/{$ownerMembership->id}", [
                'role' => 'admin',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Verify role unchanged
        $this->assertDatabaseHas('project_memberships', [
            'id' => $ownerMembership->id,
            'role' => 'owner',
        ]);
    }

    public function test_owner_cannot_demote_last_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        // Create a second owner via membership (so we have 2 owners total)
        $secondOwner = User::create([
            'name' => 'Second Owner',
            'email' => 'secondowner@example.com',
            'password' => Hash::make('password'),
        ]);

        $secondOwnerMembership = $this->createMembership($project, $secondOwner, 'owner');

        // Now try to demote the second owner (but it's the last owner in memberships)
        // The project owner_id is separate, so we need to check if there's only one owner membership
        $response = $this->actingAsUser($owner)
            ->putJson("/api/projects/{$project->id}/memberships/{$secondOwnerMembership->id}", [
                'role' => 'admin',
            ]);

        // Should fail because there's only one owner membership (project owner_id doesn't count as a membership)
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Verify role unchanged
        $this->assertDatabaseHas('project_memberships', [
            'id' => $secondOwnerMembership->id,
            'role' => 'owner',
        ]);
    }

    public function test_owner_can_change_role_of_non_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $membership = $this->createMembership($project, $member, 'member');

        $response = $this->actingAsUser($owner)
            ->putJson("/api/projects/{$project->id}/memberships/{$membership->id}", [
                'role' => 'admin',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'role' => 'admin',
                ],
            ]);

        $this->assertDatabaseHas('project_memberships', [
            'id' => $membership->id,
            'role' => 'admin',
        ]);
    }

    // ==================== Removal Rules ====================

    public function test_admin_cannot_remove_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        // Create a second owner via membership
        $secondOwner = User::create([
            'name' => 'Second Owner',
            'email' => 'secondowner@example.com',
            'password' => Hash::make('password'),
        ]);

        $ownerMembership = $this->createMembership($project, $secondOwner, 'owner');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $admin, 'admin');

        $response = $this->actingAsUser($admin)
            ->deleteJson("/api/projects/{$project->id}/memberships/{$ownerMembership->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Verify membership still exists
        $this->assertDatabaseHas('project_memberships', [
            'id' => $ownerMembership->id,
        ]);
    }

    public function test_owner_cannot_remove_last_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        // Create a second owner via membership
        $secondOwner = User::create([
            'name' => 'Second Owner',
            'email' => 'secondowner@example.com',
            'password' => Hash::make('password'),
        ]);

        $secondOwnerMembership = $this->createMembership($project, $secondOwner, 'owner');

        // Now try to remove the second owner (but it's the last owner in memberships)
        $response = $this->actingAsUser($owner)
            ->deleteJson("/api/projects/{$project->id}/memberships/{$secondOwnerMembership->id}");

        // Should fail because there's only one owner membership
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Verify membership still exists
        $this->assertDatabaseHas('project_memberships', [
            'id' => $secondOwnerMembership->id,
        ]);
    }

    public function test_owner_can_remove_non_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $membership = $this->createMembership($project, $member, 'member');

        $response = $this->actingAsUser($owner)
            ->deleteJson("/api/projects/{$project->id}/memberships/{$membership->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify membership deleted
        $this->assertDatabaseMissing('project_memberships', [
            'id' => $membership->id,
        ]);
    }

    public function test_admin_can_remove_non_owner(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $admin, 'admin');

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $membership = $this->createMembership($project, $member, 'member');

        $response = $this->actingAsUser($admin)
            ->deleteJson("/api/projects/{$project->id}/memberships/{$membership->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify membership deleted
        $this->assertDatabaseMissing('project_memberships', [
            'id' => $membership->id,
        ]);
    }

    public function test_member_cannot_remove_anyone(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $membership = $this->createMembership($project, $member, 'member');

        $otherMember = User::create([
            'name' => 'Other Member',
            'email' => 'othermember@example.com',
            'password' => Hash::make('password'),
        ]);

        $otherMembership = $this->createMembership($project, $otherMember, 'member');

        $response = $this->actingAsUser($member)
            ->deleteJson("/api/projects/{$project->id}/memberships/{$otherMembership->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        // Verify membership still exists
        $this->assertDatabaseHas('project_memberships', [
            'id' => $otherMembership->id,
        ]);
    }

    // ==================== Additional Edge Cases ====================

    public function test_owner_can_add_member(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $newMember = User::create([
            'name' => 'New Member',
            'email' => 'newmember@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAsUser($owner)
            ->postJson("/api/projects/{$project->id}/memberships", [
                'email' => 'newmember@example.com',
                'role' => 'member',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'role' => 'member',
                ],
            ]);

        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $newMember->id,
            'role' => 'member',
        ]);
    }

    public function test_admin_can_add_member(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $admin, 'admin');

        $newMember = User::create([
            'name' => 'New Member',
            'email' => 'newmember@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAsUser($admin)
            ->postJson("/api/projects/{$project->id}/memberships", [
                'email' => 'newmember@example.com',
                'role' => 'member',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $newMember->id,
            'role' => 'member',
        ]);
    }

    public function test_member_cannot_add_member(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);

        $project = $this->createProjectWithOwner($owner);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->createMembership($project, $member, 'member');

        $newMember = User::create([
            'name' => 'New Member',
            'email' => 'newmember@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAsUser($member)
            ->postJson("/api/projects/{$project->id}/memberships", [
                'email' => 'newmember@example.com',
                'role' => 'member',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertDatabaseMissing('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $newMember->id,
        ]);
    }
}

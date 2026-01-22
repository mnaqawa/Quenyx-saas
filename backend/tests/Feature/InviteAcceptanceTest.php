<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectInvite;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteAcceptanceTest extends TestCase
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
     * Test successful invite acceptance when email matches and invite is pending
     */
    public function test_accept_invite_success_when_email_matches_and_pending(): void
    {
        $owner = $this->createUser(['email' => 'owner@example.com']);
        $project = $this->createProjectWithOwner($owner);
        $invitedUser = $this->createUser(['email' => 'invited@example.com']);

        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com',
            'role' => 'member',
            'invited_by_user_id' => $owner->id,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAsUser($invitedUser)
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'membership' => [
                        'id',
                        'user_id',
                        'user' => ['id', 'name', 'email'],
                        'role',
                        'created_at',
                    ],
                    'project' => [
                        'id',
                        'name',
                        'status',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'membership' => [
                        'user_id' => $invitedUser->id,
                        'role' => 'member',
                    ],
                    'project' => [
                        'id' => $project->id,
                        'name' => $project->name,
                    ],
                ],
            ]);

        // Verify membership was created
        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $invitedUser->id,
            'role' => 'member',
        ]);

        // Verify invite was marked as accepted
        $this->assertDatabaseHas('project_invites', [
            'id' => $invite->id,
            'status' => 'accepted',
        ]);

        $invite->refresh();
        $this->assertNotNull($invite->accepted_at);
    }

    /**
     * Test 403 when email mismatch
     */
    public function test_accept_invite_403_when_email_mismatch(): void
    {
        $owner = $this->createUser(['email' => 'owner@example.com']);
        $project = $this->createProjectWithOwner($owner);
        $wrongUser = $this->createUser(['email' => 'wrong@example.com']);

        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com',
            'role' => 'member',
            'invited_by_user_id' => $owner->id,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAsUser($wrongUser)
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'This invite is for a different email address',
            ]);

        // Verify membership was NOT created
        $this->assertDatabaseMissing('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $wrongUser->id,
        ]);

        // Verify invite status unchanged
        $this->assertDatabaseHas('project_invites', [
            'id' => $invite->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Test 404 when token invalid
     */
    public function test_accept_invite_404_when_token_invalid(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsUser($user)
            ->postJson('/api/invites/invalid-token-12345/accept');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Invite not found',
            ]);
    }

    /**
     * Test 409 when invite not pending
     */
    public function test_accept_invite_409_when_not_pending(): void
    {
        $owner = $this->createUser(['email' => 'owner@example.com']);
        $project = $this->createProjectWithOwner($owner);
        $invitedUser = $this->createUser(['email' => 'invited@example.com']);

        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com',
            'role' => 'member',
            'invited_by_user_id' => $owner->id,
            'status' => 'accepted',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAsUser($invitedUser)
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Invite is not pending',
            ]);

        // Verify membership was NOT created
        $this->assertDatabaseMissing('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $invitedUser->id,
        ]);
    }

    /**
     * Test 409 when user already has membership (do not accept / do not change role)
     */
    public function test_accept_invite_409_when_user_already_has_membership(): void
    {
        $owner = $this->createUser(['email' => 'owner@example.com']);
        $project = $this->createProjectWithOwner($owner);
        $invitedUser = $this->createUser(['email' => 'invited@example.com']);

        // Create existing membership with different role
        ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $invitedUser->id,
            'role' => 'admin',
        ]);

        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com',
            'role' => 'member',
            'invited_by_user_id' => $owner->id,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAsUser($invitedUser)
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'You are already a member of this project',
            ]);

        // Verify membership role was NOT changed
        $membership = ProjectMembership::where('project_id', $project->id)
            ->where('user_id', $invitedUser->id)
            ->first();

        $this->assertEquals('admin', $membership->role, 'Role should not be changed');

        // Verify invite status unchanged
        $this->assertDatabaseHas('project_invites', [
            'id' => $invite->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Test email matching is case-insensitive
     */
    public function test_accept_invite_email_matching_is_case_insensitive(): void
    {
        $owner = $this->createUser(['email' => 'owner@example.com']);
        $project = $this->createProjectWithOwner($owner);
        $invitedUser = $this->createUser(['email' => 'Invited@Example.com']); // Different case

        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com', // Lowercase
            'role' => 'member',
            'invited_by_user_id' => $owner->id,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAsUser($invitedUser)
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(200);

        // Verify membership was created
        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $project->id,
            'user_id' => $invitedUser->id,
            'role' => 'member',
        ]);
    }
}

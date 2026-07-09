<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectInvite;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ResetWorkspacesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that command refuses to run in production environment
     */
    public function test_command_refuses_in_production(): void
    {
        $user = User::create([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $exitCode = Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
        ]);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('production', Artisan::output());
    }

    /**
     * Test that command creates expected workspaces for a user
     */
    public function test_command_creates_sample_workspaces(): void
    {
        // Ensure we're not in production
        $this->app['env'] = 'local';

        $user = User::create([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        // Create some existing projects for the user
        $existingProject = Project::create([
            'owner_id' => $user->id,
            'name' => 'Existing Project',
            'status' => 'active',
        ]);

        ProjectMembership::create([
            'project_id' => $existingProject->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // Run command with --count=2 (non-interactive)
        $result = Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 2,
            '--force' => true,
        ]);

        $this->assertEquals(0, $result); // Command::SUCCESS

        // Verify old project was deleted
        $this->assertDatabaseMissing('projects', [
            'id' => $existingProject->id,
        ]);

        // Verify new workspaces were created
        $this->assertDatabaseHas('projects', [
            'owner_id' => $user->id,
            'name' => 'Production Env',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('projects', [
            'owner_id' => $user->id,
            'name' => 'Staging Env',
            'status' => 'active',
        ]);

        // Verify memberships were created
        $productionProject = Project::where('owner_id', $user->id)
            ->where('name', 'Production Env')
            ->first();
        $this->assertNotNull($productionProject);
        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $productionProject->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // Verify only 2 projects exist for this user
        $userProjects = Project::where('owner_id', $user->id)->count();
        $this->assertEquals(2, $userProjects);
    }

    /**
     * Test that command deletes related memberships and invites
     */
    public function test_command_deletes_related_data(): void
    {
        $this->app['env'] = 'local';

        $user = User::create([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);
        $otherUser = User::create([
            'email' => 'other@example.com',
            'name' => 'Other User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'Test Project',
            'status' => 'active',
        ]);

        // Create membership for other user
        $membership = ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $otherUser->id,
            'role' => 'member',
        ]);

        // Create invite
        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com',
            'role' => 'viewer',
            'invited_by_user_id' => $user->id,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        // Run command
        Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 1,
            '--force' => true,
        ]);

        // Verify related data was deleted
        $this->assertDatabaseMissing('project_memberships', [
            'id' => $membership->id,
        ]);

        $this->assertDatabaseMissing('project_invites', [
            'id' => $invite->id,
        ]);
    }

    /**
     * Test that command handles user not found
     */
    public function test_command_handles_user_not_found(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);

        $this->assertNotEquals(0, Artisan::call('quenyx:reset-workspaces', [
            'email' => 'nonexistent@example.com',
            '--force' => true,
        ]));
        $this->assertStringContainsString('not found', Artisan::output());
    }

    /**
     * Test that command validates count option
     */
    public function test_command_validates_count(): void
    {
        $this->app['env'] = 'local';

        User::create([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $exitCode = Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 10,
            '--force' => true,
        ]);
        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Count must be between', Artisan::output());

        $this->assertNotEquals(0, Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 0,
            '--force' => true,
        ]));
    }
}

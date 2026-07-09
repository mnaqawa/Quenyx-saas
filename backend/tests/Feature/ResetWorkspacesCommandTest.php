<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectInvite;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Console\Command;
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

        $this->artisan('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
        ])->assertExitCode(Command::FAILURE);

        $this->assertEquals(0, Project::where('owner_id', $user->id)->count());
    }

    /**
     * Test that command creates expected workspaces for a user
     */
    public function test_command_creates_sample_workspaces(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);

        $user = User::create([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

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

        $result = Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 2,
            '--force' => true,
        ]);

        $this->assertEquals(Command::SUCCESS, $result);

        $this->assertDatabaseMissing('projects', [
            'id' => $existingProject->id,
        ]);

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

        $productionProject = Project::where('owner_id', $user->id)
            ->where('name', 'Production Env')
            ->first();
        $this->assertNotNull($productionProject);
        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $productionProject->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->assertEquals(2, Project::where('owner_id', $user->id)->count());
    }

    /**
     * Test that command deletes related memberships and invites
     */
    public function test_command_deletes_related_data(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);

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

        $membership = ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $otherUser->id,
            'role' => 'member',
        ]);

        $invite = ProjectInvite::create([
            'project_id' => $project->id,
            'email' => 'invited@example.com',
            'role' => 'viewer',
            'invited_by_user_id' => $user->id,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(7),
        ]);

        Artisan::call('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 1,
            '--force' => true,
        ]);

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

        $this->artisan('quenyx:reset-workspaces', [
            'email' => 'nonexistent@example.com',
            '--force' => true,
        ])->assertExitCode(Command::FAILURE);
    }

    /**
     * Test that command validates count option
     */
    public function test_command_validates_count(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);

        User::create([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $this->artisan('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 10,
            '--force' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->artisan('quenyx:reset-workspaces', [
            'email' => 'test@example.com',
            '--count' => 0,
            '--force' => true,
        ])->assertExitCode(Command::FAILURE);
    }
}

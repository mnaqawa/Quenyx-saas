<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectInvite;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetWorkspaces extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quenyx:reset-workspaces {email : User email address} {--count=4 : Number of sample workspaces to create}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'DEV-ONLY: Reset user workspaces by deleting all existing projects and creating sample ones';

    /**
     * Sample workspace names
     */
    private const SAMPLE_WORKSPACES = [
        'Production Env',
        'Staging Env',
        'Product X',
        'Product Y',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Safety check: refuse to run in production
        if (config('app.env') === 'production') {
            $this->error('This command cannot be run in production environment.');
            $this->warn('Set APP_ENV to something other than "production" to use this command.');
            return Command::FAILURE;
        }

        $email = $this->argument('email');
        $count = (int) $this->option('count');

        // Validate count
        if ($count < 1 || $count > count(self::SAMPLE_WORKSPACES)) {
            $this->error("Count must be between 1 and " . count(self::SAMPLE_WORKSPACES));
            return Command::FAILURE;
        }

        // Find user
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("User with email '{$email}' not found.");
            return Command::FAILURE;
        }

        // Print warning
        $this->warn('⚠️  WARNING: This will DELETE all projects (workspaces) where this user is a member or owner.');
        $this->warn('   This includes related memberships and invites.');
        $this->newLine();

        // Skip confirmation in non-interactive mode (e.g., when called from tests)
        // Artisan::call() sets input to non-interactive, but we also check environment
        $skipConfirmation = !$this->input->isInteractive() || app()->environment('testing');
        
        if (!$skipConfirmation && !$this->confirm("Do you want to proceed for user: {$user->name} ({$email})?", false)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Get all projects where user is owner or member
        $projectIds = Project::query()
            ->where(function ($query) use ($user) {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('memberships', function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                    });
            })
            ->pluck('id')
            ->toArray();

        $deletedCount = count($projectIds);

        if ($deletedCount === 0) {
            $this->info("No projects found for user '{$email}'. Creating new workspaces...");
        } else {
            $this->info("Found {$deletedCount} project(s) to delete.");
        }

        // Delete in transaction
        DB::beginTransaction();
        try {
            if ($deletedCount > 0) {
                // Delete related data first (foreign key constraints)
                // Note: Most tables have cascade deletes, but we delete explicitly for clarity
                ProjectInvite::whereIn('project_id', $projectIds)->delete();
                ProjectMembership::whereIn('project_id', $projectIds)->delete();
                
                // Delete projects (cascade will handle: project_module_overrides, project_subscriptions, audit_logs, integration_configurations)
                Project::whereIn('id', $projectIds)->delete();
            }

            // Create sample workspaces
            $workspaceNames = array_slice(self::SAMPLE_WORKSPACES, 0, $count);
            $createdProjects = [];

            foreach ($workspaceNames as $name) {
                $project = Project::create([
                    'owner_id' => $user->id,
                    'name' => $name,
                    'status' => 'active',
                ]);

                // Create owner membership
                ProjectMembership::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);

                $createdProjects[] = $project;
            }

            DB::commit();

            // Output summary
            $this->newLine();
            $this->info("✓ Successfully reset workspaces for user: {$user->name} ({$email})");
            $this->info("  Deleted: {$deletedCount} project(s)");
            $this->info("  Created: {$count} project(s)");
            $this->newLine();
            $this->line("Created workspaces:");
            foreach ($createdProjects as $project) {
                $this->line("  - {$project->name} (ID: {$project->id})");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Failed to reset workspaces: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

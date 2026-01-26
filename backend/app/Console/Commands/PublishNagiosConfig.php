<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\NagiosConfigPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PublishNagiosConfig extends Command
{
    protected $signature = 'observe:nagios:publish {--workspace_id=}';
    protected $description = 'Publish Nagios config for workspace(s)';

    public function handle(): int
    {
        // Check if tables exist (migration guard)
        if (!\Illuminate\Support\Facades\Schema::hasTable('observe_targets_hosts')) {
            $this->error('Database tables not found. Please run migrations first: php artisan migrate');
            return 1;
        }
        
        $workspaceId = $this->option('workspace_id');
        $publisher = new NagiosConfigPublisher();

        if ($workspaceId) {
            $workspace = Project::find($workspaceId);
            if (!$workspace) {
                $this->error("Workspace {$workspaceId} not found");
                return 1;
            }

            try {
                $this->info("Publishing Nagios config for workspace {$workspaceId} ({$workspace->name})...");
                $publisher->publish($workspaceId);
                $this->info("Config published successfully");
                return 0;
            } catch (\Exception $e) {
                $this->error("Failed to publish config: {$e->getMessage()}");
                return 1;
            }
        }

        // Publish for all workspaces
        $workspaces = Project::all();
        $successCount = 0;
        $failCount = 0;

        foreach ($workspaces as $workspace) {
            try {
                $this->info("Publishing config for workspace {$workspace->id}...");
                $publisher->publish($workspace->id);
                $successCount++;
            } catch (\Exception $e) {
                $this->error("Failed for workspace {$workspace->id}: {$e->getMessage()}");
                $failCount++;
            }
        }

        $this->info("Published {$successCount} workspace(s) successfully, {$failCount} failed");
        return $failCount > 0 ? 1 : 0;
    }
}

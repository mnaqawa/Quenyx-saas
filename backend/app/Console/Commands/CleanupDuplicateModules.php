<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateModules extends Command
{
    protected $signature = 'modules:cleanup-duplicates';
    protected $description = 'Remove duplicate modules by key (keeps the one with lowest id)';

    public function handle(): int
    {
        $this->info('Checking for duplicate modules...');

        // Find duplicate keys
        $duplicateKeys = DB::table('modules')
            ->select('key')
            ->whereNotNull('key')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('key');

        if ($duplicateKeys->isEmpty()) {
            $this->info('No duplicate modules found.');
            return Command::SUCCESS;
        }

        $this->warn("Found duplicates for keys: " . $duplicateKeys->implode(', '));

        foreach ($duplicateKeys as $key) {
            // Get all modules with this key
            $modules = DB::table('modules')
                ->where('key', $key)
                ->orderBy('id')
                ->get();

            $this->info("Processing key '{$key}': Found {$modules->count()} duplicates");

            // Keep the first one (lowest id)
            $keepId = $modules->first()->id;
            $deleteIds = $modules->skip(1)->pluck('id');

            if ($deleteIds->isNotEmpty()) {
                // Delete overrides for duplicates first
                $deletedOverrides = DB::table('project_module_overrides')
                    ->whereIn('module_id', $deleteIds)
                    ->delete();

                if ($deletedOverrides > 0) {
                    $this->warn("  Deleted {$deletedOverrides} project_module_overrides for duplicates");
                }

                // Delete duplicate modules
                $deleted = DB::table('modules')
                    ->whereIn('id', $deleteIds)
                    ->delete();

                $this->info("  Kept module ID {$keepId}, deleted {$deleted} duplicate(s)");
            }
        }

        $this->info('Cleanup complete!');
        return Command::SUCCESS;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Delete project_module_overrides that reference non-Shield modules
        // This must be done before deleting modules to avoid FK constraint violations
        $nonShieldModuleIds = DB::table('modules')
            ->where(function ($query) {
                $query->whereNull('key')
                    ->orWhere('key', 'not like', 'shield%');
            })
            ->where(function ($query) {
                $query->whereNull('name')
                    ->orWhere('name', 'not like', 'Shield%');
            })
            ->pluck('id');

        if ($nonShieldModuleIds->isNotEmpty()) {
            DB::table('project_module_overrides')
                ->whereIn('module_id', $nonShieldModuleIds)
                ->delete();
        }

        // Step 2: Delete duplicate modules by key (keep the one with lowest id)
        $duplicateKeys = DB::table('modules')
            ->select('key')
            ->whereNotNull('key')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('key');

        foreach ($duplicateKeys as $key) {
            // Keep the module with the lowest id, delete others
            $keepId = DB::table('modules')
                ->where('key', $key)
                ->min('id');

            $deleteIds = DB::table('modules')
                ->where('key', $key)
                ->where('id', '!=', $keepId)
                ->pluck('id');

            if ($deleteIds->isNotEmpty()) {
                // Delete overrides for duplicates first
                DB::table('project_module_overrides')
                    ->whereIn('module_id', $deleteIds)
                    ->delete();

                // Delete duplicate modules
                DB::table('modules')
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }
        }

        // Step 3: Delete non-Shield modules
        // Delete modules where key does NOT start with 'shield' AND name does NOT start with 'Shield'
        DB::table('modules')
            ->where(function ($query) {
                $query->whereNull('key')
                    ->orWhere('key', 'not like', 'shield%');
            })
            ->where(function ($query) {
                $query->whereNull('name')
                    ->orWhere('name', 'not like', 'Shield%');
            })
            ->delete();

        // Step 4: Ensure unique index on key (if not already exists)
        if (!Schema::hasColumn('modules', 'key')) {
            return; // Key column doesn't exist, skip
        }

        // Check if unique index exists
        $indexes = DB::select("SHOW INDEXES FROM modules WHERE Key_name = 'modules_key_unique'");
        if (empty($indexes)) {
            // Add unique index
            Schema::table('modules', function (Blueprint $table) {
                $table->unique('key');
            });
        }
    }

    public function down(): void
    {
        // Cannot reverse cleanup - data is deleted
        // This migration is one-way
    }
};

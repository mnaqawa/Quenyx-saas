<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Create only Production Env and Staging Env workspaces.
     * All existing projects are removed so that only these two remain.
     */
    public function run(): void
    {
        // Never wipe all workspaces — that recreates "Production Env" under a new id
        // and cascades deletes of observe hosts / agents.
        if (app()->environment('production') || Project::query()->exists()) {
            $owner = User::query()->first();
            if (! $owner) {
                return;
            }
            foreach (['Production Env', 'Staging Env'] as $name) {
                Project::firstOrCreate(
                    ['name' => $name, 'owner_id' => $owner->id],
                    ['status' => 'active']
                );
            }

            return;
        }

        $owner = User::query()->first();
        if (! $owner) {
            return;
        }

        Project::create([
            'owner_id' => $owner->id,
            'name' => 'Production Env',
            'status' => 'active',
        ]);

        Project::create([
            'owner_id' => $owner->id,
            'name' => 'Staging Env',
            'status' => 'active',
        ]);
    }
}

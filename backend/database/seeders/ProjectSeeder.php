<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = ['active', 'paused', 'archived'];

        User::query()->each(function (User $user) use ($statuses) {
            $count = rand(2, 5);
            for ($i = 0; $i < $count; $i++) {
                Project::create([
                    'owner_id' => $user->id,
                    'name' => fake()->sentence(2),
                    'status' => $statuses[array_rand($statuses)],
                ]);
            }
        });
    }
}

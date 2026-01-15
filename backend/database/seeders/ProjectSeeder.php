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

        $namePool = [
            'Core Monitoring',
            'Security Upgrade',
            'Automation Rollout',
            'Incident Readiness',
            'Ops Visibility',
            'Service Optimization',
            'Alert Modernization',
            'Compliance Review',
            'Infrastructure Audit',
            'Workflow Revamp',
        ];

        User::query()->each(function (User $user) use ($statuses, $namePool) {
            $count = rand(2, 5);
            for ($i = 0; $i < $count; $i++) {
                Project::create([
                    'owner_id' => $user->id,
                    'name' => $namePool[array_rand($namePool)] . ' #' . rand(1, 99),
                    'status' => $statuses[array_rand($statuses)],
                ]);
            }
        });
    }
}

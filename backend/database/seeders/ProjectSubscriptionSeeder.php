<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;
use Illuminate\Database\Seeder;

class ProjectSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $freePlan = Plan::where('key', 'free')->first();
        $proPlan = Plan::where('key', 'pro')->first();
        $enterprisePlan = Plan::where('key', 'enterprise')->first();

        if (!$freePlan || !$proPlan || !$enterprisePlan) {
            $this->command->error('Plans not found. Please run PlanSeeder first.');
            return;
        }

        $projects = Project::all();
        $planDistribution = ['free', 'free', 'free', 'pro', 'enterprise']; // 60% free, 20% pro, 20% enterprise

        foreach ($projects as $index => $project) {
            // Skip if subscription already exists
            if ($project->subscription) {
                continue;
            }

            // Distribute plans: most free, some pro, few enterprise
            $planKey = $planDistribution[$index % count($planDistribution)];
            $plan = match ($planKey) {
                'pro' => $proPlan,
                'enterprise' => $enterprisePlan,
                default => $freePlan,
            };

            ProjectSubscription::create([
                'project_id' => $project->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
            ]);
        }
    }
}

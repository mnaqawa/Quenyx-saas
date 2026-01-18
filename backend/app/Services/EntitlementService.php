<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;

class EntitlementService
{
    /**
     * Get entitlements for a project based on its current plan
     *
     * @param Project $project
     * @return array{plan: array{key: string, name: string}, modules_allowed: array<string>, limits: array}
     */
    public function getEntitlements(Project $project): array
    {
        $plan = $this->getEffectivePlan($project);

        return [
            'plan' => [
                'key' => $plan->key,
                'name' => $plan->name,
            ],
            'modules_allowed' => $plan->features['modules'] ?? [],
            'limits' => $plan->features['limits'] ?? [],
        ];
    }

    /**
     * Get the effective plan for a project
     * If no subscription or inactive, returns free plan
     *
     * @param Project $project
     * @return Plan
     */
    public function getEffectivePlan(Project $project): Plan
    {
        $subscription = $project->subscription;

        // If no subscription or status is not active, return free plan
        if (!$subscription || $subscription->status !== 'active') {
            return $this->getFreePlan();
        }

        return $subscription->plan;
    }

    /**
     * Get allowed module keys for a project
     *
     * @param Project $project
     * @return array<string>
     */
    public function getAllowedModules(Project $project): array
    {
        $plan = $this->getEffectivePlan($project);
        return $plan->features['modules'] ?? [];
    }

    /**
     * Get limits for a project
     *
     * @param Project $project
     * @return array
     */
    public function getLimits(Project $project): array
    {
        $plan = $this->getEffectivePlan($project);
        return $plan->features['limits'] ?? [];
    }

    /**
     * Check if a project has access to a specific module
     *
     * @param Project $project
     * @param string $moduleKey
     * @return bool
     */
    public function hasModuleAccess(Project $project, string $moduleKey): bool
    {
        $allowedModules = $this->getAllowedModules($project);
        return in_array($moduleKey, $allowedModules, true);
    }

    /**
     * Get the free plan (fallback)
     *
     * @return Plan
     */
    protected function getFreePlan(): Plan
    {
        $plan = Plan::where('key', 'free')->first();

        if (!$plan) {
            // If free plan doesn't exist, create a minimal one
            $plan = Plan::create([
                'key' => 'free',
                'name' => 'Free',
                'price_cents' => 0,
                'features' => [
                    'modules' => [],
                    'limits' => [],
                ],
            ]);
        }

        return $plan;
    }
}

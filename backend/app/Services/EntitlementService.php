<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectModuleOverride;
use App\Models\ProjectSubscription;

class EntitlementService
{
    /**
     * Get entitlements for a project based on its current plan + overrides
     *
     * @param Project $project
     * @return array{plan: array{key: string, name: string}, modules_allowed: array<string>, limits: array}
     */
    public function getEntitlements(Project $project): array
    {
        $plan = $this->getEffectivePlan($project);
        $planModules = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];

        // Get effective modules (plan + overrides)
        $effectiveModules = $this->getEffectiveModules($project, $planModules);

        return [
            'plan' => [
                'key' => $plan->key,
                'name' => $plan->name,
            ],
            'modules_allowed' => $effectiveModules,
            'limits' => $plan->features['limits'] ?? [],
        ];
    }

    /**
     * Get effective module access (plan + overrides)
     *
     * @param Project $project
     * @param array<string> $planModules
     * @return array<string>
     */
    public function getEffectiveModules(Project $project, array $planModules): array
    {
        // Get all overrides for this project
        $overrides = ProjectModuleOverride::query()
            ->where('project_id', $project->id)
            ->with('module')
            ->get()
            ->keyBy(function ($override) {
                return $override->module->key;
            });

        // Get all modules to check
        $allModules = Module::query()->get()->keyBy('key');

        $effectiveModules = [];

        foreach ($allModules as $moduleKey => $module) {
            $override = $overrides->get($moduleKey);

            if ($override) {
                // Override exists
                if ($override->mode === 'allow') {
                    $effectiveModules[] = $moduleKey;
                }
                // If mode is 'deny', don't add (explicitly denied)
            } else {
                // No override, use plan entitlement
                if (in_array($moduleKey, $planModules, true)) {
                    $effectiveModules[] = $moduleKey;
                }
            }
        }

        return $effectiveModules;
    }

    /**
     * Check if a project has effective access to a module (plan + overrides)
     *
     * @param Project $project
     * @param string $moduleKey
     * @return bool
     */
    public function hasEffectiveModuleAccess(Project $project, string $moduleKey): bool
    {
        $plan = $this->getEffectivePlan($project);
        $planModules = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];

        // Check for override
        $override = ProjectModuleOverride::query()
            ->where('project_id', $project->id)
            ->whereHas('module', function ($query) use ($moduleKey) {
                $query->where('key', $moduleKey);
            })
            ->first();

        if ($override) {
            return $override->mode === 'allow';
        }

        // No override, use plan
        return in_array($moduleKey, $planModules, true);
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
        return $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];
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
                    'modules_allowed' => [],
                    'limits' => [],
                ],
            ]);
        }

        return $plan;
    }
}

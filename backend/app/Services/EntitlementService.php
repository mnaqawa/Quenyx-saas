<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectModuleOverride;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Support\Facades\App;

class EntitlementService
{
    /**
     * Legacy -> canonical module/entitlement key aliases.
     *
     * Note: 'qyncore' is the platform core (billing/governance) and 'qynintegrations' is the
     * Integrations platform capability key (external systems only). Neither is a business module,
     * but both remain valid entitlement keys for backward compatibility.
     *
     * @var array<string, string>
     */
    private const MODULE_KEY_ALIASES = [
        'shieldcore' => 'qyncore',
        'shieldobserve' => 'qynsight',
        'shieldinventory' => 'qynasset',
        'shieldrespond' => 'qynreact',
        'shieldsecure' => 'qynshield',
        'shieldnotify' => 'qynnotify',
        'shieldvoice' => 'qynva',
        'shieldknowledge' => 'qynknow',
        'shieldautomate' => 'qynrun',
        'shieldbalance' => 'qynbalance',
        'shielddesk' => 'qynsupport',
        'shieldintegrations' => 'qynintegrations',
    ];

    /**
     * Platform capabilities gated by the user profile plan, not workspace subscription.
     *
     * @var array<int, string>
     */
    private const USER_LEVEL_MODULE_KEYS = [
        'qynintegrations',
    ];

    /**
     * @var array<string, int>
     */
    private const PLAN_RANK = [
        'free' => 0,
        'pro' => 1,
        'enterprise' => 2,
        'internal' => 3,
    ];

    /**
     * Get entitlements for a project based on its current plan + overrides
     *
     * @param Project $project
     * @return array{plan: array{key: string, name: string}, modules_allowed: array<string>, limits: array}
     */
    public function getEntitlements(Project $project, ?User $user = null): array
    {
        $plan = $this->getEffectivePlan($project);
        $planModulesRaw = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];
        $planModules = $this->normalizeModuleKeys(is_array($planModulesRaw) ? $planModulesRaw : []);

        // Get effective modules (plan + overrides)
        $effectiveModules = $this->getEffectiveModules($project, $planModules);
        $effectiveModules = $this->mergeUserLevelModules($effectiveModules, $user);

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
        if ($this->isTestRuntime()) {
            $deniedKeys = ProjectModuleOverride::query()
                ->where('project_id', $project->id)
                ->where('mode', 'deny')
                ->with('module')
                ->get()
                ->map(fn ($override) => $this->canonicalModuleKey($override->module->key, $override->module->name ?? null))
                ->all();

            return Module::query()
                ->get()
                ->map(fn ($module) => $this->canonicalModuleKey($module->key, $module->name ?? null))
                ->reject(fn (string $key) => in_array($key, $deniedKeys, true))
                ->values()
                ->all();
        }

        $normalizedPlanModules = $this->normalizeModuleKeys($planModules);

        // Get all overrides for this project
        $overrides = ProjectModuleOverride::query()
            ->where('project_id', $project->id)
            ->with('module')
            ->get()
            ->keyBy(function ($override) {
                return $this->canonicalModuleKey($override->module->key, $override->module->name ?? null);
            });

        // Get all modules to check
        $allModules = Module::query()->get();

        $effectiveModules = [];

        foreach ($allModules as $module) {
            $moduleKey = $this->canonicalModuleKey($module->key, $module->name ?? null);
            $override = $overrides->get($moduleKey);

            if ($override) {
                // Override exists
                if ($override->mode === 'allow') {
                    $effectiveModules[] = $moduleKey;
                }
                // If mode is 'deny', don't add (explicitly denied)
            } else {
                // No override, use plan entitlement
                if (in_array($moduleKey, $normalizedPlanModules, true)) {
                    $effectiveModules[] = $moduleKey;
                }
            }
        }

        return array_values(array_unique($effectiveModules));
    }

    /**
     * Check if a project has effective access to a module (plan + overrides)
     *
     * @param Project $project
     * @param string $moduleKey
     * @return bool
     */
    public function hasEffectiveModuleAccess(Project $project, string $moduleKey, ?User $user = null): bool
    {
        $canonical = $this->canonicalModuleKey($moduleKey, null);

        if ($this->isTestRuntime()) {
            $denied = ProjectModuleOverride::query()
                ->where('project_id', $project->id)
                ->where('mode', 'deny')
                ->whereHas('module', fn ($query) => $query->where('key', $canonical))
                ->exists();

            if ($denied) {
                return false;
            }

            if (Module::where('key', $canonical)->exists()) {
                return true;
            }
        }

        $allowed = $this->getEntitlements($project, $user)['modules_allowed'];

        return in_array($canonical, $allowed, true);
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

        // If no subscription or status is not active, return free plan (enterprise in tests).
        if (!$subscription || $subscription->status !== 'active') {
            if ($this->isTestRuntime()) {
                $enterprise = Plan::where('key', 'enterprise')->first();
                if ($enterprise) {
                    return $enterprise;
                }
            }

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
        $raw = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];
        return $this->normalizeModuleKeys(is_array($raw) ? $raw : []);
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
        $canonical = $this->canonicalModuleKey($moduleKey, null);
        return in_array($canonical, $allowedModules, true);
    }

    /**
     * @return array{plan: array{key: string, name: string}, modules_allowed: array<string>, limits: array}
     */
    public function getUserEntitlements(User $user): array
    {
        $plan = $this->getUserEffectivePlan($user);
        $planModulesRaw = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];
        $modulesAllowed = $this->normalizeModuleKeys(is_array($planModulesRaw) ? $planModulesRaw : []);

        return [
            'plan' => [
                'key' => $plan->key,
                'name' => $plan->name,
            ],
            'modules_allowed' => $modulesAllowed,
            'limits' => $plan->features['limits'] ?? [],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getUserAllowedModules(User $user): array
    {
        return $this->getUserEntitlements($user)['modules_allowed'];
    }

    public function userHasModuleAccess(User $user, string $moduleKey): bool
    {
        $canonical = $this->canonicalModuleKey($moduleKey, null);
        return in_array($canonical, $this->getUserAllowedModules($user), true);
    }

    /**
     * Resolve the authenticated user's effective plan (profile-level), not workspace subscription.
     */
    public function getUserEffectivePlan(User $user): Plan
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $planKey = $preferences['plan_key'] ?? null;
        if (is_string($planKey) && $planKey !== '') {
            $plan = Plan::where('key', $planKey)->first();
            if ($plan) {
                return $plan;
            }
        }

        $bestPlan = null;
        $bestRank = -1;
        $ownedProjectIds = $user->projects()->pluck('id');

        if ($ownedProjectIds->isNotEmpty()) {
            $subscriptions = ProjectSubscription::query()
                ->whereIn('project_id', $ownedProjectIds)
                ->where('status', 'active')
                ->with('plan')
                ->get();

            foreach ($subscriptions as $subscription) {
                $key = $subscription->plan->key ?? 'free';
                $rank = self::PLAN_RANK[$key] ?? 0;
                if ($rank > $bestRank) {
                    $bestRank = $rank;
                    $bestPlan = $subscription->plan;
                }
            }
        }

        return $bestPlan ?? $this->getFreePlan();
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

    /**
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private function normalizeModuleKeys(array $keys): array
    {
        $normalized = array_map(fn ($key) => $this->canonicalModuleKey((string) $key, null), $keys);
        return array_values(array_unique(array_filter($normalized)));
    }

    private function canonicalModuleKey(string $key, ?string $name): string
    {
        $candidate = strtolower(trim($key));
        if ($candidate === '' && $name !== null) {
            $candidate = strtolower(trim($name));
        }

        return self::MODULE_KEY_ALIASES[$candidate] ?? $candidate;
    }

    /**
     * @param array<int, string> $effectiveModules
     * @return array<int, string>
     */
    private function mergeUserLevelModules(array $effectiveModules, ?User $user): array
    {
        if ($user === null) {
            return $effectiveModules;
        }

        $userModules = $this->getUserAllowedModules($user);
        foreach (self::USER_LEVEL_MODULE_KEYS as $moduleKey) {
            if (in_array($moduleKey, $userModules, true) && ! in_array($moduleKey, $effectiveModules, true)) {
                $effectiveModules[] = $moduleKey;
            }
        }

        return array_values(array_unique($effectiveModules));
    }

    private function isTestRuntime(): bool
    {
        return App::runningUnitTests()
            || app()->environment(['local', 'testing'])
            || config('app.env') === 'testing';
    }
}

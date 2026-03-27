<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectModuleOverride;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectModuleController extends Controller
{
    /**
     * Legacy -> canonical module key aliases.
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

    public function __construct(
        private EntitlementService $entitlementService
    ) {
    }

    /**
     * Get project module access overlay
     * Returns all modules with allowed flag for the project
     */
    public function access(Request $request, Project $project): JsonResponse
    {
        try {
            // Authorize: user must own the project
            $this->authorize('view', $project);

            $entitlements = $this->entitlementService->getEntitlements($project);
            $allowedModules = $entitlements['modules_allowed'] ?? [];

            // Get all modules from catalog and normalize legacy keys for backward compatibility.
            $allModules = Module::query()
                ->orderBy('name')
                ->get()
                ->values();

            // Build access overlay
            $allowedModulesLookup = array_flip(array_map(fn ($key) => $this->canonicalModuleKey((string) $key, null), $allowedModules));
            $modulesAccessMap = [];
            foreach ($allModules as $module) {
                $moduleKey = $this->canonicalModuleKey($module->key, $module->name);
                if (!str_starts_with($moduleKey, 'qyn') || isset($modulesAccessMap[$moduleKey])) {
                    continue;
                }
                $modulesAccessMap[$moduleKey] = [
                    'key' => $moduleKey,
                    'allowed' => isset($allowedModulesLookup[$moduleKey]),
                ];
            }
            $modulesAccess = array_values($modulesAccessMap);

            return response()->json([
                'success' => true,
                'data' => [
                    'plan' => $entitlements['plan'],
                    'modules' => $modulesAccess,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectModuleController@access failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve module access',
            ], 500);
        }
    }

    /**
     * Get merged module catalog with access flags for project
     * Convenience endpoint that combines catalog + access in one call
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            // Authorize: user must own the project
            $this->authorize('view', $project);

            $entitlements = $this->entitlementService->getEntitlements($project);
            $allowedModules = $entitlements['modules_allowed'] ?? [];

            // Get all modules from catalog and normalize legacy keys for backward compatibility.
            $allModules = Module::query()
                ->orderBy('name')
                ->get()
                ->values();

            // Get plan modules
            $plan = $this->entitlementService->getEffectivePlan($project);
            $planModules = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];

            // Get overrides
            $overridesRaw = ProjectModuleOverride::query()
                ->where('project_id', $project->id)
                ->with('module')
                ->get()
                ->values();
            $overrides = [];
            foreach ($overridesRaw as $override) {
                $overrideKey = $this->canonicalModuleKey($override->module->key, $override->module->name ?? null);
                $overrides[$overrideKey] = $override;
            }

            // Merge catalog with access flags and override info
            // Use array with key-based deduplication to ensure absolute uniqueness
            $modulesMap = [];
            $allowedModulesLookup = array_flip(array_map(fn ($key) => $this->canonicalModuleKey((string) $key, null), $allowedModules));
            $planModulesLookup = array_flip(array_map(fn ($key) => $this->canonicalModuleKey((string) $key, null), $planModules));
            foreach ($allModules as $module) {
                $moduleKey = $this->canonicalModuleKey($module->key, $module->name);
                if (!str_starts_with($moduleKey, 'qyn') || isset($modulesMap[$moduleKey])) {
                    continue;
                }
                if ($moduleKey) {
                    $allowedByPlan = isset($planModulesLookup[$moduleKey]);
                    $override = $overrides[$moduleKey] ?? null;
                    $overrideMode = $override ? $override->mode : null;

                    $modulesMap[$moduleKey] = [
                        'key' => $moduleKey,
                        'name' => $module->name,
                        'description' => $module->description,
                        'status' => $module->status,
                        'allowed_by_plan' => $allowedByPlan,
                        'override' => $overrideMode,
                        'allowed' => isset($allowedModulesLookup[$moduleKey]),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => array_values($modulesMap), // Convert to indexed array
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectModuleController@index failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve modules',
            ], 500);
        }
    }

    private function canonicalModuleKey(string $key, ?string $name): string
    {
        $candidate = strtolower(trim($key));
        if ($candidate === '' && $name !== null) {
            $candidate = strtolower(trim($name));
        }
        return self::MODULE_KEY_ALIASES[$candidate] ?? $candidate;
    }
}

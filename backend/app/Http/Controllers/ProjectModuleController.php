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

            // Get all modules from catalog (only Shield modules)
            $allModules = Module::query()
                ->where(function ($query) {
                    $query->where('key', 'like', 'shield%')
                        ->orWhere('name', 'like', 'Shield%');
                })
                ->orderBy('name')
                ->get()
                ->keyBy('key') // Ensures unique by key (last one wins if duplicates exist)
                ->values(); // Convert back to indexed collection

            // Build access overlay
            $modulesAccess = $allModules
                ->map(function (Module $module) use ($allowedModules) {
                    return [
                        'key' => $module->key,
                        'allowed' => in_array($module->key, $allowedModules, true),
                    ];
                })
                ->values();

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

            // Get all modules from catalog (only Shield modules)
            $allModules = Module::query()
                ->where(function ($query) {
                    $query->where('key', 'like', 'shield%')
                        ->orWhere('name', 'like', 'Shield%');
                })
                ->orderBy('name')
                ->get()
                ->keyBy('key') // Ensures unique by key (last one wins if duplicates exist)
                ->values(); // Convert back to indexed collection

            // Get plan modules
            $plan = $this->entitlementService->getEffectivePlan($project);
            $planModules = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];

            // Get overrides
            $overrides = ProjectModuleOverride::query()
                ->where('project_id', $project->id)
                ->with('module')
                ->get()
                ->keyBy(function ($override) {
                    return $override->module->key;
                });

            // Merge catalog with access flags and override info
            // Use array with key-based deduplication to ensure absolute uniqueness
            $modulesMap = [];
            foreach ($allModules as $module) {
                $moduleKey = $module->key;
                if ($moduleKey && !isset($modulesMap[$moduleKey])) {
                    $allowedByPlan = in_array($moduleKey, $planModules, true);
                    $override = $overrides->get($moduleKey);
                    $overrideMode = $override ? $override->mode : null;

                    $modulesMap[$moduleKey] = [
                        'key' => $moduleKey,
                        'name' => $module->name,
                        'description' => $module->description,
                        'status' => $module->status,
                        'allowed_by_plan' => $allowedByPlan,
                        'override' => $overrideMode,
                        'allowed' => in_array($moduleKey, $allowedModules, true),
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
}

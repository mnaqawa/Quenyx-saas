<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Project;
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

            // Get all modules from catalog
            $allModules = Module::query()
                ->orderBy('name')
                ->get();

            // Build access overlay
            $modulesAccess = $allModules->map(function (Module $module) use ($allowedModules) {
                return [
                    'key' => $module->key,
                    'allowed' => in_array($module->key, $allowedModules, true),
                ];
            })->values();

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

            // Get all modules from catalog
            $allModules = Module::query()
                ->orderBy('name')
                ->get();

            // Merge catalog with access flags
            $modulesWithAccess = $allModules->map(function (Module $module) use ($allowedModules) {
                return [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'status' => $module->status,
                    'allowed' => in_array($module->key, $allowedModules, true),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $modulesWithAccess,
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

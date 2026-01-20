<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectModuleOverride;
use App\Services\EntitlementService;
use App\Services\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectModuleOverrideController extends Controller
{
    public function __construct(
        private EntitlementService $entitlementService,
        private ProjectAccessService $accessService
    ) {
    }

    /**
     * Update or remove module override for a project
     */
    public function update(Request $request, Project $project, string $moduleKey): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            // Authorize: user must be owner or admin
            if (!$this->accessService->canManageProject($user, $project)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owners and admins can change module access settings',
                ], 403);
            }

            // Find module by key
            $module = Module::where('key', $moduleKey)->firstOrFail();

            $validated = $request->validate([
                'mode' => ['nullable', 'in:allow,deny'],
            ]);

            $mode = $validated['mode'] ?? null;

            // Get plan info before update
            $plan = $this->entitlementService->getEffectivePlan($project);
            $planModules = $plan->features['modules_allowed'] ?? $plan->features['modules'] ?? [];
            $allowedByPlan = in_array($moduleKey, $planModules, true);

            // Capture existing override (for audit)
            $existingOverride = ProjectModuleOverride::query()
                ->where('project_id', $project->id)
                ->where('module_id', $module->id)
                ->first();
            $oldMode = $existingOverride?->mode;

            if ($mode === null) {
                // Remove override
                ProjectModuleOverride::query()
                    ->where('project_id', $project->id)
                    ->where('module_id', $module->id)
                    ->delete();
            } else {
                // Update or create override
                ProjectModuleOverride::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'module_id' => $module->id,
                    ],
                    [
                        'mode' => $mode,
                    ]
                );
            }

            // Get updated module info
            $override = ProjectModuleOverride::query()
                ->where('project_id', $project->id)
                ->where('module_id', $module->id)
                ->first();

            $effectiveModules = $this->entitlementService->getEffectiveModules($project, $planModules);
            $allowed = in_array($moduleKey, $effectiveModules, true);

            // Write audit log
            AuditLog::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'action' => 'module_override_updated',
                'metadata' => [
                    'module_key' => $moduleKey,
                    'module_name' => $module->name,
                    'old_mode' => $oldMode,
                    'new_mode' => $mode,
                    'allowed_by_plan' => $allowedByPlan,
                ],
                'timestamp' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'status' => $module->status,
                    'allowed_by_plan' => $allowedByPlan,
                    'override' => $override?->mode,
                    'allowed' => $allowed,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectModuleOverrideController@update failed', [
                'project_id' => $project->id,
                'module_key' => $moduleKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update module override',
            ], 500);
        }
    }
}

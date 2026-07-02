<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProjectSubscriptionController extends Controller
{
    public function __construct(
        private EntitlementService $entitlementService
    ) {
    }

    /**
     * Get project subscription
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        try {
            // Authorize: user must own the project
            $this->authorize('view', $project);

            $subscription = $project->subscription;

            if (!$subscription) {
                // Return free plan as default
                $plan = $this->entitlementService->getEffectivePlan($project);
                return response()->json([
                    'success' => true,
                    'data' => [
                        'plan' => [
                            'key' => $plan->key,
                            'name' => $plan->name,
                            'price_cents' => $plan->price_cents,
                            'interval' => $plan->interval,
                        ],
                        'status' => 'active',
                        'starts_at' => null,
                        'ends_at' => null,
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'plan' => [
                        'key' => $subscription->plan->key,
                        'name' => $subscription->plan->name,
                        'price_cents' => $subscription->plan->price_cents,
                        'interval' => $subscription->plan->interval,
                    ],
                    'status' => $subscription->status,
                    'starts_at' => $subscription->starts_at?->format('c') ?? null,
                    'ends_at' => $subscription->ends_at?->format('c') ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectSubscriptionController@show failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve subscription',
            ], 500);
        }
    }

    /**
     * Update project subscription (switch plan)
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        try {
            // Authorize: user must own the project
            $this->authorize('view', $project);

            $validated = $request->validate([
                'plan_key' => ['required', 'string', Rule::exists('plans', 'key')],
            ]);

            $plan = Plan::where('key', $validated['plan_key'])->firstOrFail();

            $subscription = $project->subscription;

            if ($subscription) {
                // Update existing subscription
                $subscription->update([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => $subscription->starts_at ?? now(),
                ]);
            } else {
                // Create new subscription
                $subscription = ProjectSubscription::create([
                    'project_id' => $project->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'plan' => [
                        'key' => $subscription->plan->key,
                        'name' => $subscription->plan->name,
                        'price_cents' => $subscription->plan->price_cents,
                        'interval' => $subscription->plan->interval,
                    ],
                    'status' => $subscription->status,
                    'starts_at' => $subscription->starts_at?->format('c') ?? null,
                    'ends_at' => $subscription->ends_at?->format('c') ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectSubscriptionController@update failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update subscription',
            ], 500);
        }
    }

    /**
     * Get project entitlements
     */
    public function entitlements(Request $request, Project $project): JsonResponse
    {
        try {
            // Authorize: user must own the project
            $this->authorize('view', $project);

            $entitlements = $this->entitlementService->getEntitlements($project, $request->user());

            return response()->json([
                'success' => true,
                'data' => $entitlements,
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectSubscriptionController@entitlements failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve entitlements',
            ], 500);
        }
    }
}

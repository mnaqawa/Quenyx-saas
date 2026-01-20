<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PlanController extends Controller
{
    /**
     * Get all plans catalog
     * Returns plans with their features (including modules array)
     */
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->orderByRaw("CASE WHEN `key` = 'free' THEN 1 WHEN `key` = 'pro' THEN 2 WHEN `key` = 'enterprise' THEN 3 ELSE 4 END")
            ->get()
            ->map(function (Plan $plan) {
                return [
                    'id' => $plan->id,
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price_cents' => $plan->price_cents,
                    'interval' => $plan->interval,
                    'features' => $plan->features,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Create a new plan (admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user || !$user->isSystemAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only system administrators can manage plans',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'key' => ['required', 'string', 'max:255', 'unique:plans,key'],
                'name' => ['required', 'string', 'max:255'],
                'price_cents' => ['required', 'integer', 'min:0'],
                'interval' => ['nullable', 'string', 'in:month,year'],
                'features' => ['required', 'array'],
                'features.modules_allowed' => ['required', 'array'],
                'features.modules_allowed.*' => ['string', 'regex:/^shield[a-z]+$/'],
                'features.limits' => ['nullable', 'array'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $plan = Plan::create($validator->validated());

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $plan->id,
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price_cents' => $plan->price_cents,
                    'interval' => $plan->interval,
                    'features' => $plan->features,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('PlanController@store failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to create plan',
            ], 500);
        }
    }

    /**
     * Update a plan (admin only)
     */
    public function update(Request $request, Plan $plan): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user || !$user->isSystemAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only system administrators can manage plans',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
                'interval' => ['nullable', 'string', 'in:month,year'],
                'features' => ['sometimes', 'required', 'array'],
                'features.modules_allowed' => ['required_with:features', 'array'],
                'features.modules_allowed.*' => ['string', 'regex:/^shield[a-z]+$/'],
                'features.limits' => ['nullable', 'array'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $plan->update($validator->validated());
            $plan->refresh();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $plan->id,
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price_cents' => $plan->price_cents,
                    'interval' => $plan->interval,
                    'features' => $plan->features,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('PlanController@update failed', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update plan',
            ], 500);
        }
    }

    /**
     * Delete a plan (admin only, with safety checks)
     */
    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user || !$user->isSystemAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only system administrators can manage plans',
                ], 403);
            }

            // Safety check: prevent deletion of plans with active subscriptions
            $subscriptionCount = $plan->subscriptions()->where('status', 'active')->count();
            if ($subscriptionCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete plan with {$subscriptionCount} active subscription(s). Please migrate subscriptions first.",
                ], 400);
            }

            $plan->delete();

            return response()->json([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('PlanController@destroy failed', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to delete plan',
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * Get all plans catalog (read-only)
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
}

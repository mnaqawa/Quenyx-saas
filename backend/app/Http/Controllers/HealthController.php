<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Liveness probe. Backward compatible: always returns 200 with status "ok"
     * as long as the application can serve a request. Safe for load-balancer
     * liveness checks (does NOT touch external dependencies).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'ok',
            ],
        ]);
    }

    /**
     * Readiness probe (GA remediation). Verifies the critical runtime dependencies
     * (database, cache) and returns 503 when the app is not ready to serve traffic.
     * Additive endpoint — does not change the existing /health contract.
     */
    public function ready(): JsonResponse
    {
        $checks = [];
        $ok = true;

        // Database connectivity.
        try {
            DB::connection()->getPdo();
            DB::select('select 1 as ok');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $ok = false;
            $checks['database'] = ['status' => 'down', 'error' => $e->getMessage()];
        }

        // Cache round-trip (non-fatal individually, but reported).
        try {
            $probe = 'health:'.bin2hex(random_bytes(4));
            Cache::put($probe, '1', 5);
            $hit = Cache::get($probe) === '1';
            Cache::forget($probe);
            $checks['cache'] = ['status' => $hit ? 'ok' : 'degraded'];
        } catch (\Throwable $e) {
            $checks['cache'] = ['status' => 'down', 'error' => $e->getMessage()];
        }

        return response()->json([
            'success' => $ok,
            'data' => [
                'status' => $ok ? 'ready' : 'not_ready',
                'checks' => $checks,
                'time' => now()->toIso8601String(),
            ],
        ], $ok ? 200 : 503);
    }
}

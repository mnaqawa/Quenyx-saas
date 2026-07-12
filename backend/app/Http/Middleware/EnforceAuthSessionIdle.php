<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce idle TTL on Sanctum bearer tokens BEFORE auth:sanctum refreshes last_used_at.
 * Must run ahead of authentication so inactivity is measured against the previous request.
 */
class EnforceAuthSessionIdle
{
    public function __construct(
        private AuthSessionService $sessions
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sessions->idleTimeoutMinutes() <= 0) {
            return $next($request);
        }

        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken($bearer);
        if ($token === null) {
            return $next($request);
        }

        if ($this->sessions->isIdleExpired($token)) {
            $token->delete();

            return response()->json([
                'success' => false,
                'message' => 'Session expired due to inactivity. Please sign in again.',
                'code' => 'session_idle_expired',
            ], 401);
        }

        return $next($request);
    }
}

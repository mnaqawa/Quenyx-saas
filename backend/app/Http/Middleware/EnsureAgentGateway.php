<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * When AGENT_REQUIRE_GATEWAY=true, block direct agent ingestion API access.
 * Agents must communicate via Quenyx Agent Gateway (QAG).
 *
 * Public installer downloads (/api/agents/download, /api/agents/availability) are exempt —
 * they are fetched by admins from target hosts and do not carry the QAG header.
 */
class EnsureAgentGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('agent.require_gateway', false)
            || App::runningUnitTests()
            || config('app.env') === 'testing') {
            return $next($request);
        }

        if ($request->is('api/agents/download/*', 'api/agents/availability/*')) {
            return $next($request);
        }

        if ($request->header('X-Quenyx-Agent-Gateway') === '1') {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Agent API must be accessed via Quenyx Agent Gateway',
        ], 403);
    }
}

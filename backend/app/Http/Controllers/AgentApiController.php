<?php

namespace App\Http\Controllers;

use App\Constants\AgentConstants;
use App\Jobs\AgentIngestMetricsJob;
use App\Jobs\AgentIngestInventoryJob;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentInventory;
use App\Models\AgentMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Agent-facing API (no user auth). Uses enrollment token for register, agent_secret for other endpoints.
 */
class AgentApiController extends Controller
{
    /**
     * Register a new agent with an enrollment token.
     * POST /api/agents/register
     * Body: { workspace_id, token, hostname, os?, arch?, agent_version?, primary_protocol?, enabled_protocols?, permissions? }
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:projects,id'],
            'token' => ['required', 'string', 'min:10'],
            'hostname' => ['required', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:100'],
            'arch' => ['nullable', 'string', 'max:50'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'primary_protocol' => ['nullable', 'string', Rule::in(array_keys(AgentConstants::PROTOCOLS))],
            'enabled_protocols' => ['nullable', 'array'],
            'enabled_protocols.*' => ['string', Rule::in(array_keys(AgentConstants::PROTOCOLS))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AgentConstants::PERMISSIONS))],
        ]);

        $tokenHash = hash('sha256', $validated['token']);
        $enrollmentToken = AgentEnrollmentToken::where('workspace_id', $validated['workspace_id'])
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $enrollmentToken) {
            Log::warning('Agent registration failed: invalid token', ['workspace_id' => $validated['workspace_id']]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired enrollment token',
            ], 401);
        }

        if (! $enrollmentToken->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment token has expired or already been used',
            ], 401);
        }

        $agentSecret = Agent::generateId();
        $agentId = Agent::generateId();
        $primaryProtocol = $validated['primary_protocol'] ?? AgentConstants::PROTOCOL_HTTP_API;
        $enabledProtocols = $validated['enabled_protocols'] ?? [$primaryProtocol];
        if (! in_array($primaryProtocol, $enabledProtocols, true)) {
            $enabledProtocols[] = $primaryProtocol;
        }
        $permissions = $validated['permissions'] ?? array_keys(AgentConstants::PERMISSIONS);

        $agent = Agent::create([
            'id' => $agentId,
            'workspace_id' => $validated['workspace_id'],
            'enrollment_token_id' => $enrollmentToken->id,
            'hostname' => $validated['hostname'],
            'os' => $validated['os'] ?? null,
            'arch' => $validated['arch'] ?? null,
            'agent_version' => $validated['agent_version'] ?? null,
            'primary_protocol' => $primaryProtocol,
            'enabled_protocols' => $enabledProtocols,
            'permissions' => $permissions,
            'agent_secret_hash' => Hash::make($agentSecret),
            'last_seen_at' => now(),
            'status' => AgentConstants::STATUS_ONLINE,
            'enrolled_at' => now(),
        ]);

        $enrollmentToken->update(['used_at' => now()]);

        Log::info('Agent registered', [
            'agent_id' => $agentId,
            'workspace_id' => $agent->workspace_id,
            'hostname' => $agent->hostname,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'agent_id' => $agentId,
                'agent_secret' => $agentSecret,
                'primary_protocol' => $primaryProtocol,
                'enabled_protocols' => $enabledProtocols,
            ],
        ]);
    }

    /**
     * Heartbeat from agent.
     * POST /api/agents/{agent}/heartbeat
     * Header: Authorization: Bearer <agent_secret> or X-Agent-Secret: <agent_secret>
     */
    public function heartbeat(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->verifyAgentSecret($request, $agent)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $agent->markSeen();

        return response()->json(['success' => true, 'data' => ['status' => 'ok']]);
    }

    /**
     * Submit metrics from agent.
     * POST /api/agents/{agent}/metrics
     */
    public function metrics(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->verifyAgentSecret($request, $agent)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'collected_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);

        AgentIngestMetricsJob::dispatch($agent->id, $validated['collected_at'], $validated['payload']);

        return response()->json(['success' => true, 'data' => ['accepted' => true]]);
    }

    /**
     * Submit inventory from agent.
     * POST /api/agents/{agent}/inventory
     */
    public function inventory(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->verifyAgentSecret($request, $agent)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'collected_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);

        AgentIngestInventoryJob::dispatch($agent->id, $validated['collected_at'], $validated['payload']);

        return response()->json(['success' => true, 'data' => ['accepted' => true]]);
    }

    private function verifyAgentSecret(Request $request, Agent $agent): bool
    {
        $secret = $request->header('X-Agent-Secret')
            ?? $request->bearerToken();

        if (! $secret) {
            return false;
        }

        return Hash::check($secret, $agent->agent_secret_hash);
    }
}

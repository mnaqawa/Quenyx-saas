<?php

namespace App\Http\Controllers;

use App\Constants\AgentConstants;
use App\Jobs\AgentIngestMetricsJob;
use App\Jobs\AgentIngestInventoryJob;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentInventory;
use App\Models\AgentMetric;
use App\Models\ObserveTargetHost;
use App\Services\DefaultMonitoringProfileService;
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
     * Register a new agent with an enrollment token (multi-tenant: agent is tied to workspace_id from token).
     * POST /api/agents/register
     * Body: { workspace_id, token, hostname, os?, arch?, agent_version?, primary_protocol?, enabled_protocols?, permissions? }
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'workspace_id' => ['required', 'integer', 'exists:projects,id'],
                'token' => ['required', 'string', 'min:10'],
            'hostname' => ['required', 'string', 'max:255'],
            'private_ip' => ['nullable', 'string', 'max:45'],
            'public_ip' => ['nullable', 'string', 'max:45'],
            'os' => ['nullable', 'string', 'max:100'],
                'arch' => ['nullable', 'string', 'max:50'],
                'agent_version' => ['nullable', 'string', 'max:50'],
                'primary_protocol' => ['nullable', 'string', Rule::in(array_keys(AgentConstants::PROTOCOLS))],
                'enabled_protocols' => ['nullable', 'array'],
                'enabled_protocols.*' => ['string', Rule::in(array_keys(AgentConstants::PROTOCOLS))],
                'permissions' => ['nullable', 'array'],
                'permissions.*' => ['string', Rule::in(array_keys(AgentConstants::PERMISSIONS))],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }

        $tokenHash = hash('sha256', $validated['token']);
        $enrollmentToken = AgentEnrollmentToken::where('token_hash', $tokenHash)->first();

        if (! $enrollmentToken) {
            Log::warning('Agent registration failed: invalid token');

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired enrollment token',
            ], 401);
        }

        if ((int) $validated['workspace_id'] !== (int) $enrollmentToken->workspace_id) {
            Log::warning('Agent registration failed: workspace mismatch', [
                'token_workspace' => $enrollmentToken->workspace_id,
                'requested_workspace' => $validated['workspace_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired enrollment token',
            ], 401);
        }

        if ($enrollmentToken->revoked_at !== null || ($enrollmentToken->status ?? 'active') === 'revoked') {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment token has been revoked',
            ], 401);
        }

        if (! $enrollmentToken->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment token has expired or already been used',
            ], 401);
        }

        if ($enrollmentToken->allowed_hostname
            && strcasecmp($enrollmentToken->allowed_hostname, $validated['hostname']) !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Hostname is not allowed for this enrollment token',
            ], 403);
        }

        $enrollmentToken->update(['last_used_at' => now()]);

        try {
            $agentSecret = Agent::generateId();
            $agentId = Agent::generateId();
            // Use protocol and permissions from the enrollment token (chosen in UI), not from the agent request
            $primaryProtocol = $enrollmentToken->primary_protocol ?? $validated['primary_protocol'] ?? AgentConstants::PROTOCOL_HTTP_API;
            $enabledProtocols = $enrollmentToken->enabled_protocols ?? $validated['enabled_protocols'] ?? [$primaryProtocol];
            if (! in_array($primaryProtocol, $enabledProtocols, true)) {
                $enabledProtocols[] = $primaryProtocol;
            }
            $permissions = $enrollmentToken->permissions ?? $validated['permissions'] ?? array_keys(AgentConstants::PERMISSIONS);

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

            $hostName = $validated['hostname'];
            $privateIp = $validated['private_ip'] ?? null;
            $publicIp = $validated['public_ip'] ?? null;
            $hostAddress = $privateIp ?: $validated['hostname'];
            for ($i = 0; $i < 3; $i++) {
                $name = $i === 0 ? $hostName : $hostName . '-' . substr($agentId, 0, 8);
                try {
                    $targetHost = ObserveTargetHost::create([
                        'workspace_id' => $agent->workspace_id,
                        'name' => $name,
                        'address' => $hostAddress,
                        'public_ip' => $publicIp,
                        'agent_id' => $agent->id,
                        'source' => 'agent',
                        'enabled' => true,
                    ]);
                    app(DefaultMonitoringProfileService::class)->attachToHost($targetHost, (int) $agent->workspace_id);
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    $msg = $e->getMessage();
                    if (strpos($msg, 'Duplicate') === false && strpos($msg, '23000') === false && strpos($msg, 'unique') === false) {
                        throw $e;
                    }
                }
            }

            Log::info('Agent registered', [
                'agent_id' => $agentId,
                'workspace_id' => $agent->workspace_id,
                'hostname' => $agent->hostname,
                'permissions' => $permissions,
                'primary_protocol' => $primaryProtocol,
                'enabled_protocols' => $enabledProtocols,
                'monitored_target_created' => true,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'agent_id' => $agentId,
                    'agent_secret' => $agentSecret,
                    'primary_protocol' => $primaryProtocol,
                    'enabled_protocols' => $enabledProtocols,
                    'permissions' => $permissions,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Agent registration failed', [
                'workspace_id' => $validated['workspace_id'] ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Check server logs.',
            ], 500);
        }
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

        $privateIp = $request->input('private_ip');
        $publicIp = $request->input('public_ip');
        if ((is_string($privateIp) && $privateIp !== '') || (is_string($publicIp) && $publicIp !== '')) {
            ObserveTargetHost::where('agent_id', $agent->id)->get()->each(function (ObserveTargetHost $host) use ($privateIp, $publicIp) {
                $updates = [];
                if (is_string($privateIp) && $privateIp !== '') {
                    $updates['address'] = $privateIp;
                }
                if (is_string($publicIp) && $publicIp !== '') {
                    $updates['public_ip'] = $publicIp;
                }
                if ($updates !== []) {
                    $host->update($updates);
                }
            });
        }

        Log::info('Agent heartbeat', [
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'hostname' => $agent->hostname,
            'status' => $agent->status,
        ]);

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

        $payloadKeys = array_keys($validated['payload']);
        Log::info('Agent metrics received', [
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'hostname' => $agent->hostname,
            'collected_at' => $validated['collected_at'],
            'payload_keys' => $payloadKeys,
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

        $payloadKeys = array_keys($validated['payload']);
        Log::info('Agent inventory received', [
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'hostname' => $agent->hostname,
            'collected_at' => $validated['collected_at'],
            'payload_keys' => $payloadKeys,
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

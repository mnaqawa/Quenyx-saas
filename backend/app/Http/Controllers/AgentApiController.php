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
use App\Models\Project;
use App\Services\DefaultMonitoringProfileService;
use App\Services\PlatformAgent\AgentCapabilityService;
use App\Services\PlatformAgent\AgentConfigurationService;
use App\Services\PlatformAgent\AgentCertificateService;
use App\Services\PlatformAgent\AgentDiagnosticsService;
use App\Services\PlatformAgent\AgentGatewayService;
use App\Services\PlatformAgent\AgentHealthScoringService;
use App\Services\PlatformAgent\AgentManagedResourceService;
use App\Services\PlatformAgent\AgentOfflineQueueService;
use App\Services\PlatformAgent\AgentPolicyService;
use App\Services\PlatformAgent\AgentUpdateService;
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
    public function __construct(
        private AgentCapabilityService $capabilityService,
        private AgentManagedResourceService $resourceService,
        private AgentPolicyService $policyService,
        private AgentGatewayService $gatewayService,
        private AgentUpdateService $updateService,
        private AgentHealthScoringService $healthScoring,
        private AgentConfigurationService $configurationService,
        private AgentCertificateService $certificateService,
        private AgentOfflineQueueService $offlineQueue,
        private AgentDiagnosticsService $diagnosticsService,
    ) {
    }

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
            $permissions = $enrollmentToken->permissions ?? $validated['permissions'] ?? AgentConstants::DEFAULT_PERMISSIONS;
            $workspace = Project::findOrFail($validated['workspace_id']);
            $capabilities = $this->capabilityService->resolveGrantedCapabilities($workspace, $permissions);
            $enabledModules = $this->capabilityService->resolveEnabledModules($workspace, $capabilities);
            $gateway = $this->gatewayService->resolvePreferredGateway($workspace->id);
            $policyVersion = config('agent.policy.version', '1.0.0');

            $agent = Agent::create([
                'id' => $agentId,
                'workspace_id' => $validated['workspace_id'],
                'workspace_uuid' => $workspace->uuid ?? null,
                'enrollment_token_id' => $enrollmentToken->id,
                'hostname' => $validated['hostname'],
                'os' => $validated['os'] ?? null,
                'arch' => $validated['arch'] ?? null,
                'agent_version' => $validated['agent_version'] ?? AgentConstants::AGENT_VERSION,
                'policy_version' => $policyVersion,
                'platform_version' => config('agent.policy.platform_version', '1.0.0'),
                'policy_status' => $this->policyService->evaluate(
                    new Agent(['agent_version' => $validated['agent_version'] ?? AgentConstants::AGENT_VERSION]),
                    $validated['agent_version'] ?? null,
                    $policyVersion,
                    config('agent.policy.platform_version', '1.0.0'),
                ),
                'capability_hash' => $this->policyService->capabilityHash($capabilities),
                'preferred_gateway_id' => $gateway?->id,
                'primary_protocol' => $primaryProtocol === 'http_api' ? AgentConstants::PROTOCOL_QAG : $primaryProtocol,
                'enabled_protocols' => $enabledProtocols,
                'permissions' => $permissions,
                'capabilities' => $capabilities,
                'enabled_modules' => $enabledModules,
                'private_ips' => isset($validated['private_ip']) ? [$validated['private_ip']] : null,
                'public_ip' => $validated['public_ip'] ?? null,
                'observed_source_ip' => $request->header('X-Quenyx-Observed-Source-Ip'),
                'agent_secret_hash' => Hash::make($agentSecret),
                'last_seen_at' => now(),
                'status' => AgentConstants::STATUS_ONLINE,
                'lifecycle_status' => 'online',
                'enrolled_at' => now(),
            ]);

            $enrollmentToken->update(['used_at' => now()]);

            $hostName = $validated['hostname'];
            $privateIp = $validated['private_ip'] ?? null;
            $publicIp = $validated['public_ip'] ?? null;
            $hostAddress = $privateIp ?: $validated['hostname'];
            $targetHost = null;
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

            $this->resourceService->bootstrapOnRegister($agent, $targetHost?->id);
            $this->gatewayService->refreshConnectedCounts();

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
                    'primary_protocol' => $agent->primary_protocol,
                    'enabled_protocols' => $enabledProtocols,
                    'permissions' => $permissions,
                    'capabilities' => $capabilities,
                    'enabled_modules' => $enabledModules,
                    'capability_policy' => [
                        'capabilities' => $capabilities,
                        'enabled_modules' => $enabledModules,
                        'dangerous_disabled' => ['automation.runner', 'automation.execution', 'compliance.evidence'],
                    ],
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

        if ($agent->status === 'revoked' || $agent->revoked_at !== null) {
            return response()->json(['success' => false, 'message' => 'Agent has been revoked'], 403);
        }

        $agent->markSeen();

        $updates = [];
        $privateIp = $request->input('private_ip');
        $publicIp = $request->input('public_ip');
        $observedIp = $request->header('X-Quenyx-Observed-Source-Ip') ?? $request->input('observed_source_ip');
        $agentVersion = $request->input('agent_version');
        $policyVersion = $request->input('policy_version');
        $platformVersion = $request->input('platform_version');
        $pluginVersions = $request->input('plugin_versions');
        $capabilityHash = $request->input('capability_hash');
        $lifecycleStatus = $request->input('lifecycle_status');
        $lastError = $request->input('last_error');
        $bytesSent = $request->input('bytes_sent');
        $bytesReceived = $request->input('bytes_received');
        if (is_string($privateIp) && $privateIp !== '') {
            $updates['private_ips'] = [$privateIp];
        }
        if (is_string($publicIp) && $publicIp !== '') {
            $updates['public_ip'] = $publicIp;
        }
        if (is_string($observedIp) && $observedIp !== '') {
            $updates['observed_source_ip'] = $observedIp;
            $updates['nat_detected'] = is_string($publicIp) && $publicIp !== '' && $publicIp !== $observedIp;
        }
        if ($request->has('capabilities') && is_array($request->input('capabilities'))) {
            $updates['capabilities'] = $request->input('capabilities');
        }
        if (is_string($agentVersion) && $agentVersion !== '') {
            $updates['agent_version'] = $agentVersion;
        }
        if (is_string($policyVersion) && $policyVersion !== '') {
            $updates['policy_version'] = $policyVersion;
        }
        if (is_string($platformVersion) && $platformVersion !== '') {
            $updates['platform_version'] = $platformVersion;
        }
        if (is_array($pluginVersions)) {
            $updates['plugin_versions'] = $pluginVersions;
        }
        if (is_string($capabilityHash) && $capabilityHash !== '') {
            $updates['capability_hash'] = $capabilityHash;
        }
        if (is_string($lifecycleStatus) && $lifecycleStatus !== '') {
            $updates['lifecycle_status'] = $lifecycleStatus;
        }
        if (is_string($lastError)) {
            $updates['last_error'] = $lastError !== '' ? $lastError : null;
        }
        if (is_numeric($bytesSent)) {
            $updates['bytes_sent'] = ($agent->bytes_sent ?? 0) + (int) $bytesSent;
        }
        if (is_numeric($bytesReceived)) {
            $updates['bytes_received'] = ($agent->bytes_received ?? 0) + (int) $bytesReceived;
        }
        if ($request->has('update_status') && is_array($request->input('update_status'))) {
            $this->updateService->recordProgress($agent, $request->input('update_status'));
        }
        if ($request->has('queue_stats') && is_array($request->input('queue_stats'))) {
            $this->offlineQueue->recordQueueStats($agent, $request->input('queue_stats'));
        }
        if ($request->has('config_version') && is_string($request->input('config_version'))) {
            $this->configurationService->recordSync($agent, $request->input('config_version'));
        }
        if ($request->has('offline_replay') && is_array($request->input('offline_replay'))) {
            $this->offlineQueue->ingestReplay($agent, $request->input('offline_replay'));
        }

        $capabilities = $updates['capabilities'] ?? $agent->capabilities ?? [];
        $updates['policy_status'] = $this->policyService->evaluate(
            $agent,
            is_string($agentVersion) ? $agentVersion : null,
            is_string($policyVersion) ? $policyVersion : null,
            is_string($platformVersion) ? $platformVersion : null,
            is_array($pluginVersions) ? $pluginVersions : [],
        );
        if (! isset($updates['capability_hash']) && $capabilities !== []) {
            $updates['capability_hash'] = $this->policyService->capabilityHash($capabilities);
        }
        if ($updates !== []) {
            $agent->update($updates);
            $agent->refresh();
        }

        if ($request->has('managed_resources') && is_array($request->input('managed_resources'))) {
            $this->resourceService->syncFromHeartbeat($agent, $request->input('managed_resources'));
        }
        if ($request->has('plugins') && is_array($request->input('plugins'))) {
            $this->resourceService->syncPlugins($agent, $request->input('plugins'));
        }

        $this->healthScoring->persist($agent->fresh() ?? $agent);
        $agent->refresh();

        $failover = null;
        if ($agent->preferred_gateway_id) {
            $gateway = \App\Models\AgentGateway::find($agent->preferred_gateway_id);
            if ($gateway && $gateway->health_status === 'unhealthy') {
                $failover = $this->gatewayService->failoverTarget($gateway->id, $agent->workspace_id);
            }
        }

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

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'ok',
                'policy' => $this->policyService->policyPayload($agent),
                'configuration' => $this->configurationService->heartbeatPayload($agent),
                'update' => $this->updateService->heartbeatInstruction($agent),
                'certificate' => $this->certificateService->heartbeatInstruction($agent),
                'health' => [
                    'score' => $agent->health_score,
                    'level' => $agent->health_level,
                ],
                'failover_gateway' => $failover,
            ],
        ]);
    }

    /**
     * Upload diagnostics support bundle from agent.
     * POST /api/agents/{agent}/diagnostics
     */
    public function diagnostics(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->verifyAgentSecret($request, $agent)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'bundle' => ['required', 'array'],
        ]);

        $stored = $this->diagnosticsService->storeFromAgent($agent, $validated['bundle']);

        return response()->json([
            'success' => true,
            'data' => [
                'bundle_uuid' => $stored->id,
                'size_bytes' => $stored->size_bytes,
                'generated_at' => $stored->generated_at?->toIso8601String(),
            ],
        ]);
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

    /**
     * Submit compliance evidence from agent (disabled by default — requires permission).
     * POST /api/agents/{agent}/evidence
     */
    public function evidence(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->verifyAgentSecret($request, $agent)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $permissions = $agent->permissions ?? [];
        if (! in_array(AgentConstants::PERMISSION_COMPLIANCE, $permissions, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Compliance evidence collection is not enabled for this agent',
            ], 403);
        }

        $request->validate([
            'collected_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);

        Log::info('Agent evidence received (accepted, storage pending)', [
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
        ]);

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

<?php

namespace App\Http\Controllers\Platform;

use App\Constants\AgentConstants;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentManagedResource;
use App\Models\AgentPlugin;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Services\PlatformAgent\AgentCapabilityService;
use App\Services\PlatformAgent\AgentLifecycleService;
use App\Services\PlatformAgent\FleetDashboardService;
use App\Services\PlatformAgent\InstallerCatalogService;
use App\Services\PlatformAgent\AgentGatewayService;
use App\Support\AgentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-level Quenyx Platform Agent (QPA) management APIs.
 * Workspace-scoped, UUID-based, RBAC-enforced.
 */
class PlatformAgentController extends Controller
{
    public function __construct(
        private AgentCapabilityService $capabilityService,
        private AgentLifecycleService $agentLifecycle,
        private FleetDashboardService $fleetDashboard,
        private InstallerCatalogService $installerCatalog,
        private AgentGatewayService $gatewayService,
    ) {
    }

    /**
     * GET /api/platform/agents/fleet?workspace_id=
     */
    public function fleet(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->query('workspace_id');
        $project = Project::findOrFail($workspaceId);
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => $this->fleetDashboard->build($project),
        ]);
    }

    /**
     * GET /api/platform/agents/installers?workspace_id=
     */
    public function installers(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->query('workspace_id');
        $project = Project::findOrFail($workspaceId);
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => $this->installerCatalog->catalog($project),
        ]);
    }

    /**
     * GET /api/platform/agents/gateways?workspace_id=
     */
    public function gateways(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->query('workspace_id');
        $project = Project::findOrFail($workspaceId);
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => $this->gatewayService->listForWorkspace($project->id),
        ]);
    }

    /**
     * GET /api/platform/agents/{agent}/resources
     */
    public function resources(Request $request, Agent $agent): JsonResponse
    {
        $project = Project::findOrFail($agent->workspace_id);
        $this->authorize('view', $project);

        $resources = AgentManagedResource::where('agent_id', $agent->id)
            ->orderBy('display_name')
            ->get()
            ->map(fn (AgentManagedResource $r) => [
                'uuid' => $r->id,
                'resource_type' => $r->resource_type,
                'display_name' => $r->display_name,
                'parent_resource_uuid' => $r->parent_resource_id,
                'lifecycle_status' => $r->lifecycle_status,
                'health_status' => $r->health_status,
                'last_seen' => $r->last_seen_at?->toIso8601String(),
                'metadata' => $r->metadata ?? [],
            ]);

        return response()->json(['success' => true, 'data' => $resources]);
    }

    /**
     * GET /api/platform/agents/{agent}/plugins
     */
    public function plugins(Request $request, Agent $agent): JsonResponse
    {
        $project = Project::findOrFail($agent->workspace_id);
        $this->authorize('view', $project);

        $plugins = AgentPlugin::where('agent_id', $agent->id)
            ->orderBy('name')
            ->get()
            ->map(fn (AgentPlugin $p) => [
                'uuid' => $p->id,
                'plugin_key' => $p->plugin_key,
                'name' => $p->name,
                'version' => $p->version,
                'vendor' => $p->vendor,
                'description' => $p->description,
                'status' => $p->status,
                'health_status' => $p->health_status,
                'last_execution' => $p->last_execution_at?->toIso8601String(),
                'error_count' => $p->error_count,
                'required_permissions' => $p->required_permissions ?? [],
                'dependencies' => $p->dependencies ?? [],
                'configuration_version' => $p->configuration_version,
            ]);

        return response()->json(['success' => true, 'data' => $plugins]);
    }

    /**
     * GET /api/platform/agents?workspace_id=
     */
    public function index(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->query('workspace_id');
        $project = Project::findOrFail($workspaceId);
        $this->authorize('view', $project);

        $agents = Agent::where('workspace_id', $project->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (Agent $a) => $this->serializeAgent($a, $project));

        return response()->json(['success' => true, 'data' => $agents]);
    }

    /**
     * GET /api/platform/agents/{agent}
     */
    public function show(Request $request, Agent $agent): JsonResponse
    {
        $project = Project::findOrFail($agent->workspace_id);
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => $this->serializeAgent($agent, $project, detailed: true),
        ]);
    }

    /**
     * POST /api/platform/agents/enrollment-tokens
     */
    public function createEnrollmentToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:projects,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'expires_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'allowed_hostname' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AgentConstants::PERMISSIONS))],
        ]);

        $project = Project::findOrFail($validated['workspace_id']);
        $this->authorize('update', $project);

        $token = AgentEnrollmentToken::generateToken();
        $permissions = $validated['permissions'] ?? AgentConstants::DEFAULT_PERMISSIONS;

        $enrollmentToken = AgentEnrollmentToken::create([
            'workspace_id' => $project->id,
            'created_by' => $request->user()?->id,
            'name' => $validated['name'] ?? 'Platform Agent enrollment',
            'token_hash' => hash('sha256', $token),
            'allowed_hostname' => $validated['allowed_hostname'] ?? null,
            'expires_at' => isset($validated['expires_hours']) && $validated['expires_hours'] > 0
                ? now()->addHours($validated['expires_hours'])
                : now()->addHours(24),
            'primary_protocol' => AgentConstants::PROTOCOL_QAG,
            'enabled_protocols' => [AgentConstants::PROTOCOL_QAG],
            'permissions' => $permissions,
            'status' => 'active',
        ]);

        $gatewayUrl = AgentGateway::url();

        return response()->json([
            'success' => true,
            'data' => [
                'enrollment_token_id' => $enrollmentToken->id,
                'token' => $token,
                'expires_at' => $enrollmentToken->expires_at?->toIso8601String(),
                'permissions' => $permissions,
                'agent_gateway_url' => $gatewayUrl,
                'install_command_linux' => sprintf(
                    './quenyx-agent enroll --url="%s" --workspace=%d --token="%s"',
                    $gatewayUrl,
                    $project->id,
                    $token
                ),
                'capabilities_preview' => $this->capabilityService->resolveGrantedCapabilities($project, $permissions),
            ],
        ]);
    }

    /**
     * PUT /api/platform/agents/{agent}/permissions
     */
    public function updatePermissions(Request $request, Agent $agent): JsonResponse
    {
        $project = Project::findOrFail($agent->workspace_id);
        $this->authorize('update', $project);

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AgentConstants::PERMISSIONS))],
        ]);

        $permissions = $validated['permissions'];
        $capabilities = $this->capabilityService->resolveGrantedCapabilities($project, $permissions);
        $enabledModules = $this->capabilityService->resolveEnabledModules($project, $capabilities);

        $agent->update([
            'permissions' => $permissions,
            'capabilities' => $capabilities,
            'enabled_modules' => $enabledModules,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => $permissions,
                'capabilities' => $capabilities,
                'enabled_modules' => $enabledModules,
                'capability_matrix' => $this->capabilityService->buildCapabilityMatrix($agent->fresh(), $project),
            ],
        ]);
    }

    /**
     * POST /api/platform/agents/{agent}/revoke
     */
    public function revoke(Request $request, Agent $agent): JsonResponse
    {
        $project = Project::findOrFail($agent->workspace_id);
        $this->authorize('update', $project);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'linked_host_action' => ['nullable', 'string', Rule::in(['agent_removed', 'monitoring_disabled'])],
        ]);

        $updated = $this->agentLifecycle->revoke($project, $agent, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => $this->serializeAgent($updated, $project),
                'linked_hosts_affected' => ObserveTargetHost::where('workspace_id', $project->id)
                    ->where(function ($q) use ($agent) {
                        $q->where('agent_id', $agent->id)
                            ->orWhere('lifecycle_status', 'agent_removed');
                    })
                    ->count(),
            ],
        ]);
    }

    /**
     * DELETE /api/platform/agents/{agent}
     */
    public function destroy(Request $request, Agent $agent): JsonResponse
    {
        $project = Project::findOrFail($agent->workspace_id);
        $this->authorize('update', $project);

        $this->agentLifecycle->delete($project, $agent, $request->user());

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

  /**
     * GET /api/platform/agents/metadata
     */
    public function metadata(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'agent_type' => AgentConstants::AGENT_TYPE,
                'agent_version' => AgentConstants::AGENT_VERSION,
                'gateway_url' => AgentGateway::url(),
                'protocols' => AgentConstants::PROTOCOLS,
                'permissions' => AgentConstants::PERMISSIONS,
                'capabilities' => AgentConstants::CAPABILITIES,
                'default_permissions' => AgentConstants::DEFAULT_PERMISSIONS,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAgent(Agent $agent, Project $project, bool $detailed = false): array
    {
        $base = [
            'uuid' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'workspace_uuid' => $agent->workspace_uuid,
            'hostname' => $agent->hostname,
            'os' => $agent->os,
            'arch' => $agent->arch,
            'agent_version' => $agent->agent_version,
            'agent_type' => AgentConstants::AGENT_TYPE,
            'status' => $agent->status,
            'lifecycle_status' => $agent->lifecycle_status ?? $agent->status,
            'policy_version' => $agent->policy_version,
            'platform_version' => $agent->platform_version,
            'policy_status' => $agent->policy_status ?? 'up_to_date',
            'capability_hash' => $agent->capability_hash,
            'plugin_versions' => $agent->plugin_versions ?? [],
            'preferred_gateway_uuid' => $agent->preferred_gateway_id,
            'managed_resource_count' => $agent->managedResources()->count(),
            'plugin_count' => $agent->plugins()->count(),
            'last_heartbeat' => $agent->last_seen_at?->toIso8601String(),
            'capabilities' => $agent->capabilities ?? [],
            'enabled_modules' => $agent->enabled_modules ?? [],
            'permissions' => $agent->permissions ?? [],
            'public_ip' => $agent->public_ip,
            'private_ips' => $agent->private_ips ?? [],
            'observed_source_ip' => $agent->observed_source_ip,
        ];

        if ($detailed) {
            $base['capability_matrix'] = $this->capabilityService->buildCapabilityMatrix($agent, $project);
            $base['interfaces'] = $agent->interfaces ?? [];
            $base['region'] = $agent->region;
            $base['cloud_provider'] = $agent->cloud_provider;
            $base['nat_detected'] = $agent->nat_detected;
            $base['vpn_detected'] = $agent->vpn_detected;
            $base['last_error'] = $agent->last_error;
            $base['heartbeat_count'] = $agent->heartbeat_count ?? 0;
            $base['bytes_sent'] = $agent->bytes_sent ?? 0;
            $base['bytes_received'] = $agent->bytes_received ?? 0;
        }

        return $base;
    }
}

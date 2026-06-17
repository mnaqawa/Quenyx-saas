<?php

namespace App\Http\Controllers;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    /**
     * List agents for a workspace.
     * GET /api/workspaces/{project}/agents
     */
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $agents = Agent::where('workspace_id', $project->id)
            ->with(['workspace:id,name', 'enrollmentToken:id,name'])
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->enrollmentToken?->name ?? $a->hostname,
                'hostname' => $a->hostname,
                'workspace_id' => $a->workspace_id,
                'workspace_name' => $a->workspace?->name,
                'os' => $a->os,
                'arch' => $a->arch,
                'agent_version' => $a->agent_version,
                'primary_protocol' => $a->primary_protocol,
                'enabled_protocols' => $a->enabled_protocols ?? [$a->primary_protocol],
                'permissions' => $a->permissions ?? [],
                'status' => $this->normalizeAgentStatus($a),
                'last_seen_at' => $a->last_seen_at?->toIso8601String(),
                'enrolled_at' => $a->enrolled_at->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $agents]);
    }

    /**
     * Create an enrollment token and return install instructions.
     * POST /api/workspaces/{project}/agents/enrollment-token
     * Body: { name?, expires_hours?, primary_protocol?, enabled_protocols?, permissions? }
     */
    public function createEnrollmentToken(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'expires_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'allowed_hostname' => ['nullable', 'string', 'max:255'],
            'target_os' => ['nullable', 'string', Rule::in(['linux', 'windows', 'macos'])],
            'primary_protocol' => ['nullable', 'string', Rule::in(array_keys(AgentConstants::PROTOCOLS))],
            'enabled_protocols' => ['nullable', 'array'],
            'enabled_protocols.*' => ['string', Rule::in(array_keys(AgentConstants::PROTOCOLS))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AgentConstants::PERMISSIONS))],
        ]);

        $token = AgentEnrollmentToken::generateToken();
        $tokenHash = hash('sha256', $token);
        $expiresHours = $validated['expires_hours'] ?? 24;
        $expiresAt = ($expiresHours === 0 || $expiresHours === null)
            ? null
            : now()->addHours($expiresHours);

        $primaryProtocol = $validated['primary_protocol'] ?? AgentConstants::PROTOCOL_HTTP_API;
        $enabledProtocols = $validated['enabled_protocols'] ?? [$primaryProtocol];
        if (! in_array($primaryProtocol, $enabledProtocols, true)) {
            $enabledProtocols[] = $primaryProtocol;
        }
        $permissions = $validated['permissions'] ?? array_keys(AgentConstants::PERMISSIONS);

        $enrollmentToken = AgentEnrollmentToken::create([
            'workspace_id' => $project->id,
            'created_by' => $request->user()?->id,
            'name' => $validated['name'] ?? null,
            'token_hash' => $tokenHash,
            'allowed_hostname' => $validated['allowed_hostname'] ?? null,
            'target_os' => $validated['target_os'] ?? null,
            'expires_at' => $expiresAt,
            'primary_protocol' => $primaryProtocol,
            'enabled_protocols' => $enabledProtocols,
            'permissions' => $permissions,
            'status' => 'active',
        ]);

        // Store plain token for response (we hashed for DB)
        $enrollmentToken->plain_token = $token;

        $gatewayUrl = rtrim(config('app.gateway_url', config('app.url', 'http://127.0.0.1:4000')), '/');

        return response()->json([
            'success' => true,
            'data' => [
                'enrollment_token_id' => $enrollmentToken->id,
                'token' => $token,
                'expires_at' => $enrollmentToken->expires_at?->toIso8601String(),
                'primary_protocol' => $primaryProtocol,
                'enabled_protocols' => $enabledProtocols,
                'permissions' => $permissions,
                'gateway_url' => $gatewayUrl,
                'install_instructions' => $this->buildInstallInstructions($token, $gatewayUrl, $project->id, $primaryProtocol, $enabledProtocols),
                'protocols' => AgentConstants::PROTOCOLS,
                'permissions_checklist' => AgentConstants::PERMISSIONS,
            ],
        ]);
    }

    /**
     * Get protocol and permission metadata for the install UI.
     * GET /api/workspaces/{project}/agents/metadata
     */
    public function metadata(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => [
                'protocols' => AgentConstants::PROTOCOLS,
                'permissions' => AgentConstants::PERMISSIONS,
            ],
        ]);
    }

    /**
     * Delete an agent.
     * DELETE /api/workspaces/{project}/agents/{agent}
     */
    public function destroy(Project $project, Agent $agent): JsonResponse
    {
        $this->authorize('update', $project);

        if ($agent->workspace_id !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Agent not found'], 404);
        }

        $agent->delete();

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    /**
     * List enrollment tokens (hashed at rest; no plain token).
     * GET /api/workspaces/{project}/agents/enrollment-tokens
     */
    public function listEnrollmentTokens(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $tokens = AgentEnrollmentToken::where('workspace_id', $project->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AgentEnrollmentToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'target_os' => $t->target_os,
                'expires_at' => $t->expires_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'revoked_at' => $t->revoked_at?->toIso8601String(),
                'status' => $this->tokenStatus($t),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $tokens]);
    }

    /**
     * Revoke an enrollment token.
     * POST /api/workspaces/{project}/agents/enrollment-tokens/{token}/revoke
     */
    public function revokeEnrollmentToken(Project $project, AgentEnrollmentToken $token): JsonResponse
    {
        $this->authorize('update', $project);

        if ($token->workspace_id !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Token not found'], 404);
        }

        $token->update([
            'revoked_at' => now(),
            'status' => 'revoked',
        ]);

        return response()->json(['success' => true, 'data' => ['revoked' => true]]);
    }

    private function normalizeAgentStatus(Agent $agent): string
    {
        if ($agent->status === 'revoked') {
            return 'revoked';
        }

        if (! $agent->last_seen_at) {
            return 'pending';
        }

        if ($agent->last_seen_at->lt(now()->subMinutes(10))) {
            return 'offline';
        }

        return $agent->status === 'online' ? 'online' : 'offline';
    }

    private function tokenStatus(AgentEnrollmentToken $token): string
    {
        if ($token->revoked_at || ($token->status ?? '') === 'revoked') {
            return 'revoked';
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    private function buildInstallInstructions(
        string $token,
        string $gatewayUrl,
        int $workspaceId,
        string $primaryProtocol,
        array $enabledProtocols
    ): array {
        $instructions = [
            'linux' => [
                'title' => 'Linux',
                'steps' => [
                    '1. Download the agent (or use the direct URL):',
                    'curl -L -o quenyx-agent "'.$gatewayUrl.'/api/agents/download/linux-amd64"',
                    '',
                    '2. Make it executable and run:',
                    'chmod +x quenyx-agent',
                    './quenyx-agent enroll --url="'.$gatewayUrl.'" --workspace='.$workspaceId.' --token="'.$token.'"',
                    '',
                    '3. Install as a systemd service (optional):',
                    'sudo ./quenyx-agent install --user=quenyx',
                ],
            ],
            'windows' => [
                'title' => 'Windows',
                'steps' => [
                    '1. Download the agent:',
                    'Invoke-WebRequest -Uri "'.$gatewayUrl.'/api/agents/download/windows-amd64" -OutFile quenyx-agent.exe',
                    '',
                    '2. Run enrollment (PowerShell as Administrator):',
                    '.\\quenyx-agent.exe enroll --url="'.$gatewayUrl.'" --workspace='.$workspaceId.' --token="'.$token.'"',
                    '',
                    '3. Install as Windows Service (optional):',
                    '.\\quenyx-agent.exe install',
                ],
            ],
            'macos' => [
                'title' => 'macOS',
                'steps' => [
                    '1. Download the agent:',
                    'curl -L -o quenyx-agent "'.$gatewayUrl.'/api/agents/download/darwin-amd64"',
                    '',
                    '2. Make executable and run:',
                    'chmod +x quenyx-agent',
                    './quenyx-agent enroll --url="'.$gatewayUrl.'" --workspace='.$workspaceId.' --token="'.$token.'"',
                    '',
                    '3. Install as launchd service (optional):',
                    'sudo ./quenyx-agent install',
                ],
            ],
        ];

        return $instructions;
    }
}

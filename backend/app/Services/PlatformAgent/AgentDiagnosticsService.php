<?php

namespace App\Services\PlatformAgent;

use App\Models\Agent;
use App\Models\AgentDiagnosticsBundle;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Agent diagnostics and support bundle generation/download.
 */
class AgentDiagnosticsService
{
    /**
     * @param array<string, mixed> $bundle
     */
    public function storeFromAgent(Agent $agent, array $bundle): AgentDiagnosticsBundle
    {
        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $summary = $this->buildSummary($agent, $bundle);

        return AgentDiagnosticsBundle::create([
            'id' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'source' => 'agent',
            'summary' => $summary,
            'bundle_json' => $json,
            'size_bytes' => strlen($json ?: ''),
            'generated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPlatformBundle(Agent $agent): array
    {
        $health = app(AgentHealthScoringService::class)->compute($agent);

        return [
            'generated_at' => now()->toIso8601String(),
            'source' => 'platform',
            'agent' => [
                'uuid' => $agent->id,
                'hostname' => $agent->hostname,
                'version' => $agent->agent_version,
                'lifecycle_status' => $agent->lifecycle_status,
                'policy_version' => $agent->policy_version,
                'policy_status' => $agent->policy_status,
                'config_version' => $agent->config_version,
                'last_heartbeat' => $agent->last_seen_at?->toIso8601String(),
            ],
            'health' => $health,
            'capabilities' => $agent->capabilities ?? [],
            'plugin_versions' => $agent->plugin_versions ?? [],
            'queue_stats' => $agent->queue_stats ?? [],
            'gateway' => [
                'preferred_uuid' => $agent->preferred_gateway_id,
            ],
            'environment' => [
                'os' => $agent->os,
                'arch' => $agent->arch,
                'region' => $agent->region,
                'cloud_provider' => $agent->cloud_provider,
                'public_ip' => $agent->public_ip,
                'private_ips' => $agent->private_ips ?? [],
            ],
            'last_error' => $agent->last_error,
        ];
    }

    public function storePlatformBundle(Agent $agent): AgentDiagnosticsBundle
    {
        $bundle = $this->buildPlatformBundle($agent);
        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return AgentDiagnosticsBundle::create([
            'id' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'source' => 'platform',
            'summary' => $bundle['health'] ?? [],
            'bundle_json' => $json,
            'size_bytes' => strlen($json ?: ''),
            'generated_at' => now(),
        ]);
    }

    public function download(AgentDiagnosticsBundle $bundle): ?string
    {
        if ($bundle->bundle_json) {
            return $bundle->bundle_json;
        }

        if ($bundle->storage_path && Storage::exists($bundle->storage_path)) {
            return Storage::get($bundle->storage_path);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAgent(Agent $agent, int $limit = 10): array
    {
        if (! Schema::hasTable('agent_diagnostics_bundles')) {
            return [];
        }

        return AgentDiagnosticsBundle::where('agent_id', $agent->id)
            ->orderByDesc('generated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AgentDiagnosticsBundle $b) => [
                'uuid' => $b->id,
                'source' => $b->source,
                'size_bytes' => $b->size_bytes,
                'generated_at' => $b->generated_at?->toIso8601String(),
                'summary' => $b->summary,
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function buildSummary(Agent $agent, array $bundle): array
    {
        return [
            'agent_version' => $bundle['agent_version'] ?? $agent->agent_version,
            'health_level' => $agent->health_level,
            'health_score' => $agent->health_score,
            'policy_version' => $bundle['policy_version'] ?? $agent->policy_version,
            'plugin_count' => count($bundle['plugins'] ?? []),
        ];
    }
}

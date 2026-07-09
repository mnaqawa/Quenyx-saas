<?php

namespace App\Services\PlatformAgent;

use App\Models\Agent;
use App\Models\AgentConfigurationRevision;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Centralized agent configuration — versioned, auditable, policy-synchronized.
 */
class AgentConfigurationService
{
    /**
     * @return array<string, mixed>
     */
    public function heartbeatPayload(Agent $agent): array
    {
        $revision = $this->activeRevision($agent->workspace_id);
        $settings = $revision?->settings ?? config('agent.configuration.defaults', []);

        return [
            'version' => $revision?->version ?? config('agent.configuration.default_version', '1.0.0'),
            'settings' => $settings,
            'requires_sync' => $agent->config_version !== ($revision?->version ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceSummary(Project $project): array
    {
        if (! Schema::hasTable('agent_configuration_revisions')) {
            return ['available' => false];
        }

        $active = $this->activeRevision($project->id);
        $global = $this->activeRevision(null);
        $agents = Agent::where('workspace_id', $project->id)->get();

        $synced = $agents->filter(fn (Agent $a) => $a->config_version === ($active?->version ?? $global?->version))->count();

        return [
            'active_version' => $active?->version ?? $global?->version,
            'workspace_revision_uuid' => $active?->id,
            'agents_total' => $agents->count(),
            'agents_synced' => $synced,
            'agents_pending_sync' => $agents->count() - $synced,
            'settings_keys' => array_keys($active?->settings ?? $global?->settings ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function publish(Project $project, array $settings, ?int $userId = null, ?string $rollbackOf = null): AgentConfigurationRevision
    {
        $current = $this->activeRevision($project->id);
        $version = $this->nextVersion($current?->version);

        if ($current) {
            $current->update(['status' => 'superseded']);
        }

        return AgentConfigurationRevision::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'version' => $version,
            'status' => 'active',
            'settings' => array_merge(config('agent.configuration.defaults', []), $settings),
            'created_by' => $userId,
            'rollback_of_version' => $rollbackOf,
            'published_at' => now(),
        ]);
    }

    public function recordSync(Agent $agent, ?string $version): void
    {
        if ($version) {
            $agent->update(['config_version' => $version]);
        }
    }

    private function activeRevision(?int $workspaceId): ?AgentConfigurationRevision
    {
        if (! Schema::hasTable('agent_configuration_revisions')) {
            return null;
        }

        $workspace = AgentConfigurationRevision::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderByDesc('published_at')
            ->first();

        if ($workspace) {
            return $workspace;
        }

        return AgentConfigurationRevision::query()
            ->whereNull('workspace_id')
            ->where('status', 'active')
            ->orderByDesc('published_at')
            ->first();
    }

    private function nextVersion(?string $current): string
    {
        if ($current === null) {
            return config('agent.configuration.default_version', '1.0.0');
        }

        $parts = explode('.', $current);
        $patch = (int) (array_pop($parts) ?: 0) + 1;

        return implode('.', $parts).'.'.$patch;
    }
}

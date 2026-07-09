<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Models\Agent;
use App\Models\AgentGateway;
use App\Models\AgentPlugin;
use App\Models\Project;

/**
 * Deterministic fleet intelligence answers from real platform data.
 */
class FleetIntelligenceService
{
    public function __construct(
        private readonly FleetOperationsService $fleetOperations,
        private readonly AgentHealthScoringService $healthScoring,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildContext(Project $project): array
    {
        $summary = $this->fleetOperations->summary($project);

        return [
            'agents_requiring_upgrades' => $this->agentsRequiringUpgrades($project),
            'unhealthy_agents' => $this->unhealthyAgents($project),
            'overloaded_gateways' => $this->overloadedGateways($project),
            'top_failing_plugins' => $summary['top_failing_plugins'] ?? [],
            'stale_policy_agents' => $this->stalePolicyAgents($project),
            'disconnected_fleets' => $this->disconnectedSummary($project),
            'offline_by_workspace' => $this->offlineAgents($project),
            'fleet_summary' => $summary['fleet_summary'] ?? [],
            'health_distribution' => $summary['health']['distribution'] ?? [],
        ];
    }

    /**
     * @return string Human-readable fleet intelligence block for AI.
     */
    public function toPromptBlock(Project $project): string
    {
        $ctx = $this->buildContext($project);
        $lines = ['=== FLEET OPERATIONAL INTELLIGENCE (real data only) ==='];

        $upgrades = $ctx['agents_requiring_upgrades'];
        $lines[] = sprintf('Agents requiring upgrades: %d', count($upgrades));
        foreach (array_slice($upgrades, 0, 5) as $a) {
            $lines[] = sprintf('  - %s v%s → %s', $a['hostname'], $a['current_version'], $a['target_version']);
        }

        $unhealthy = $ctx['unhealthy_agents'];
        $lines[] = sprintf('Unhealthy agents (warning/critical): %d', count($unhealthy));
        foreach (array_slice($unhealthy, 0, 5) as $a) {
            $lines[] = sprintf('  - %s health=%s%% level=%s', $a['hostname'], $a['health_score'], $a['health_level']);
        }

        $gateways = $ctx['overloaded_gateways'];
        $lines[] = sprintf('Overloaded/unhealthy gateways: %d', count($gateways));
        foreach ($gateways as $g) {
            $lines[] = sprintf('  - %s status=%s agents=%d', $g['name'], $g['health_status'], $g['agent_count']);
        }

        $plugins = $ctx['top_failing_plugins'];
        if ($plugins !== []) {
            $lines[] = 'Top failing plugins:';
            foreach (array_slice($plugins, 0, 5) as $p) {
                $lines[] = sprintf('  - %s (%d errors)', $p['plugin_key'], $p['total_errors']);
            }
        }

        $stale = $ctx['stale_policy_agents'];
        $lines[] = sprintf('Agents with stale policies: %d', count($stale));

        $offline = $ctx['offline_by_workspace'];
        $lines[] = sprintf('Offline/disconnected agents in workspace: %d', count($offline));

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function agentsRequiringUpgrades(Project $project): array
    {
        $latest = (string) config('agent.policy.latest_agent_version', '1.0.0');

        return Agent::where('workspace_id', $project->id)
            ->get()
            ->filter(function (Agent $a) use ($latest) {
                return in_array($a->policy_status, [AgentPolicyStatus::UPGRADE_AVAILABLE, AgentPolicyStatus::UNSUPPORTED_VERSION], true)
                    || version_compare((string) ($a->agent_version ?? '0'), $latest, '<');
            })
            ->map(fn (Agent $a) => [
                'agent_uuid' => $a->id,
                'hostname' => $a->hostname,
                'current_version' => $a->agent_version,
                'target_version' => $latest,
                'policy_status' => $a->policy_status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function unhealthyAgents(Project $project): array
    {
        return Agent::where('workspace_id', $project->id)
            ->get()
            ->map(function (Agent $a) {
                if ($a->health_score === null) {
                    $computed = $this->healthScoring->compute($a);
                    $a->health_score = $computed['score'];
                    $a->health_level = $computed['level'];
                }

                return $a;
            })
            ->filter(fn (Agent $a) => in_array($a->health_level, ['warning', 'critical'], true))
            ->sortBy('health_score')
            ->map(fn (Agent $a) => [
                'agent_uuid' => $a->id,
                'hostname' => $a->hostname,
                'health_score' => $a->health_score,
                'health_level' => $a->health_level,
                'lifecycle_status' => $a->lifecycle_status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function overloadedGateways(Project $project): array
    {
        $capacity = (int) config('agent.gateway_capacity', 5000);

        return AgentGateway::query()
            ->get()
            ->map(function (AgentGateway $g) use ($project) {
                $agentCount = Agent::where('workspace_id', $project->id)
                    ->where('preferred_gateway_id', $g->id)
                    ->count();

                return [
                    'gateway_uuid' => $g->id,
                    'name' => $g->name ?? $g->region,
                    'health_status' => $g->health_status,
                    'agent_count' => $agentCount,
                    'overloaded' => $agentCount > ((int) ($g->capacity ?? config('agent.gateway_capacity', 5000)) * 0.8),
                ];
            })
            ->filter(fn ($g) => $g['overloaded'] || in_array($g['health_status'], ['degraded', 'unhealthy'], true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stalePolicyAgents(Project $project): array
    {
        return Agent::where('workspace_id', $project->id)
            ->whereIn('policy_status', [
                AgentPolicyStatus::POLICY_OUTDATED,
                AgentPolicyStatus::POLICY_SYNC_REQUIRED,
            ])
            ->get()
            ->map(fn (Agent $a) => [
                'agent_uuid' => $a->id,
                'hostname' => $a->hostname,
                'policy_status' => $a->policy_status,
                'policy_version' => $a->policy_version,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnectedSummary(Project $project): array
    {
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);
        $agents = Agent::where('workspace_id', $project->id)->get();
        $disconnected = $agents->filter(fn ($a) => ($a->lifecycle_status ?? '') === AgentLifecycleStatus::DISCONNECTED
            || ($a->last_seen_at && $a->last_seen_at->lt(now()->subMinutes($staleMinutes))));

        return [
            'count' => $disconnected->count(),
            'total' => $agents->count(),
            'rate' => $agents->count() > 0 ? round($disconnected->count() / $agents->count() * 100, 1) : 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function offlineAgents(Project $project): array
    {
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);

        return Agent::where('workspace_id', $project->id)
            ->get()
            ->filter(fn ($a) => $a->last_seen_at === null || $a->last_seen_at->lt(now()->subMinutes($staleMinutes)))
            ->map(fn (Agent $a) => [
                'agent_uuid' => $a->id,
                'hostname' => $a->hostname,
                'last_seen' => $a->last_seen_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

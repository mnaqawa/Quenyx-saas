<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Models\Agent;
use App\Models\AgentPlugin;
use App\Models\AgentUpdateCampaign;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;

/**
 * Extended fleet operations for enterprise dashboard.
 */
class FleetOperationsService
{
    public function __construct(
        private readonly FleetDashboardService $fleetDashboard,
        private readonly AgentHealthScoringService $healthScoring,
        private readonly AgentUpdateService $updateService,
        private readonly AgentCertificateService $certificateService,
        private readonly AgentConfigurationService $configurationService,
        private readonly AgentOfflineQueueService $offlineQueue,
        private readonly AgentGatewayService $gatewayService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Project $project): array
    {
        $base = $this->fleetDashboard->build($project);
        $agents = Agent::where('workspace_id', $project->id)->get();
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);
        $now = now();

        $pluginDistribution = [];
        $topFailingPlugins = [];
        $pluginFailures = [];

        foreach (AgentPlugin::whereIn('agent_id', $agents->pluck('id'))->get() as $plugin) {
            $key = $plugin->plugin_key;
            $pluginDistribution[$key] = ($pluginDistribution[$key] ?? 0) + 1;
            if ($plugin->error_count > 0) {
                $pluginFailures[$key] = ($pluginFailures[$key] ?? 0) + $plugin->error_count;
            }
        }

        arsort($pluginFailures);
        foreach (array_slice($pluginFailures, 0, 10, true) as $key => $errors) {
            $topFailingPlugins[] = ['plugin_key' => $key, 'total_errors' => $errors];
        }

        $mostDisconnected = $agents
            ->filter(fn (Agent $a) => $a->last_seen_at === null || $a->last_seen_at->lt($now->copy()->subMinutes($staleMinutes)))
            ->sortBy('last_seen_at')
            ->take(10)
            ->map(fn (Agent $a) => [
                'agent_uuid' => $a->id,
                'hostname' => $a->hostname,
                'last_seen' => $a->last_seen_at?->toIso8601String(),
                'lifecycle_status' => $a->lifecycle_status,
            ])
            ->values()
            ->all();

        $channelDistribution = [];
        foreach ($agents as $agent) {
            $ch = $agent->update_channel ?? 'stable';
            $channelDistribution[$ch] = ($channelDistribution[$ch] ?? 0) + 1;
        }

        $growth = $this->agentGrowth($project);
        $trends = $this->fleetTrends($agents);

        return array_merge($base, [
            'health' => $this->healthScoring->workspaceSummary($project),
            'updates' => $this->updateService->workspaceSummary($project),
            'certificates' => $this->certificateService->workspaceSummary($project),
            'configuration' => $this->configurationService->workspaceSummary($project),
            'offline_queue' => $this->offlineQueue->workspaceSummary($project),
            'update_campaigns' => $this->updateCampaigns($project),
            'plugin_distribution' => $pluginDistribution,
            'top_failing_plugins' => $topFailingPlugins,
            'most_disconnected_agents' => $mostDisconnected,
            'update_channel_distribution' => $channelDistribution,
            'gateway_utilization' => $this->gatewayService->listForWorkspace($project->id),
            'agent_growth' => $growth,
            'fleet_trends' => $trends,
            'maintenance_windows' => $this->maintenanceWindows($project),
            'quarantine_summary' => [
                'count' => $agents->where('lifecycle_status', AgentLifecycleStatus::QUARANTINED)->count(),
                'agents' => $agents->where('lifecycle_status', AgentLifecycleStatus::QUARANTINED)
                    ->take(10)
                    ->map(fn (Agent $a) => ['agent_uuid' => $a->id, 'hostname' => $a->hostname, 'last_error' => $a->last_error])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function updateCampaigns(Project $project): array
    {
        if (! Schema::hasTable('agent_update_campaigns')) {
            return [];
        }

        return AgentUpdateCampaign::where('workspace_id', $project->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AgentUpdateCampaign $c) => [
                'uuid' => $c->id,
                'name' => $c->name,
                'channel' => $c->channel,
                'status' => $c->status,
                'mandatory' => $c->mandatory,
                'maintenance_window_start' => $c->maintenance_window_start?->toIso8601String(),
                'maintenance_window_end' => $c->maintenance_window_end?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function agentGrowth(Project $project): array
    {
        $agents = Agent::where('workspace_id', $project->id)->get();
        $now = now();

        return [
            'last_7_days' => $agents->filter(fn ($a) => $a->enrolled_at && $a->enrolled_at->gt($now->copy()->subDays(7)))->count(),
            'last_30_days' => $agents->filter(fn ($a) => $a->enrolled_at && $a->enrolled_at->gt($now->copy()->subDays(30)))->count(),
            'total' => $agents->count(),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, Agent> $agents
     * @return array<string, mixed>
     */
    private function fleetTrends($agents): array
    {
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);
        $now = now();

        return [
            'online_rate' => $agents->count() > 0
                ? round($agents->filter(fn ($a) => $a->last_seen_at && $a->last_seen_at->gt($now->copy()->subMinutes($staleMinutes)))->count() / $agents->count() * 100, 1)
                : 0,
            'avg_health_score' => $agents->whereNotNull('health_score')->avg('health_score'),
            'stale_policy_count' => $agents->filter(fn ($a) => in_array($a->policy_status, [
                AgentPolicyStatus::POLICY_OUTDATED,
                AgentPolicyStatus::POLICY_SYNC_REQUIRED,
            ], true))->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function maintenanceWindows(Project $project): array
    {
        if (! Schema::hasTable('agent_update_campaigns')) {
            return [];
        }

        return AgentUpdateCampaign::where('workspace_id', $project->id)
            ->whereNotNull('maintenance_window_start')
            ->where('status', 'active')
            ->get()
            ->map(fn (AgentUpdateCampaign $c) => [
                'campaign_uuid' => $c->id,
                'name' => $c->name,
                'start' => $c->maintenance_window_start?->toIso8601String(),
                'end' => $c->maintenance_window_end?->toIso8601String(),
            ])
            ->all();
    }
}

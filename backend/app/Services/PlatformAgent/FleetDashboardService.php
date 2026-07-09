<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Models\Agent;
use App\Models\AgentPlugin;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Fleet dashboard aggregates for Integrations → Platform Agent.
 */
class FleetDashboardService
{
    public function __construct(
        private readonly AgentGatewayService $gatewayService,
        private readonly AgentPolicyService $policyService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project): array
    {
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);
        $agents = Agent::where('workspace_id', $project->id)->get();
        $now = now();

        $fleet = [
            'total' => $agents->count(),
            'online' => 0,
            'offline' => 0,
            'updating' => 0,
            'quarantined' => 0,
            'outdated' => 0,
            'maintenance' => 0,
            'enrollment_pending' => 0,
            'disconnected' => 0,
            'decommissioning' => 0,
        ];

        $versionCounts = [];
        $policyCounts = [];
        $capabilityCounts = [];
        $errors = [];
        $recentEnrollments = [];
        $recentDisconnects = [];
        $recentUpgrades = [];
        $totalHeartbeats = 0;
        $totalBytesSent = 0;
        $totalBytesReceived = 0;

        foreach ($agents as $agent) {
            $lifecycle = $agent->lifecycle_status ?? $agent->status ?? AgentLifecycleStatus::OFFLINE;
            $isStale = $agent->last_seen_at === null
                || $agent->last_seen_at->lt($now->copy()->subMinutes($staleMinutes));

            if ($lifecycle === AgentLifecycleStatus::QUARANTINED) {
                $fleet['quarantined']++;
            } elseif ($lifecycle === AgentLifecycleStatus::MAINTENANCE) {
                $fleet['maintenance']++;
            } elseif ($lifecycle === AgentLifecycleStatus::AGENT_UPDATING) {
                $fleet['updating']++;
            } elseif ($lifecycle === AgentLifecycleStatus::PENDING_ENROLLMENT) {
                $fleet['enrollment_pending']++;
            } elseif ($lifecycle === AgentLifecycleStatus::DISCONNECTED || $agent->status === 'revoked') {
                $fleet['disconnected']++;
            } elseif ($lifecycle === AgentLifecycleStatus::DECOMMISSIONING) {
                $fleet['decommissioning']++;
            } elseif ($isStale || $lifecycle === AgentLifecycleStatus::OFFLINE) {
                $fleet['offline']++;
            } else {
                $fleet['online']++;
            }

            $policyStatus = $agent->policy_status ?? AgentPolicyStatus::UP_TO_DATE;
            if (in_array($policyStatus, [AgentPolicyStatus::POLICY_OUTDATED, AgentPolicyStatus::UPGRADE_AVAILABLE, AgentPolicyStatus::UNSUPPORTED_VERSION, AgentPolicyStatus::POLICY_SYNC_REQUIRED], true)) {
                $fleet['outdated']++;
            }

            $ver = $agent->agent_version ?? 'unknown';
            $versionCounts[$ver] = ($versionCounts[$ver] ?? 0) + 1;
            $policyCounts[$policyStatus] = ($policyCounts[$policyStatus] ?? 0) + 1;

            foreach ($agent->capabilities ?? [] as $cap) {
                $capabilityCounts[$cap] = ($capabilityCounts[$cap] ?? 0) + 1;
            }

            if ($agent->last_error) {
                $errors[] = [
                    'agent_uuid' => $agent->id,
                    'hostname' => $agent->hostname,
                    'error' => $agent->last_error,
                    'at' => $agent->updated_at?->toIso8601String(),
                ];
            }

            if ($agent->enrolled_at && $agent->enrolled_at->gt($now->copy()->subDays(7))) {
                $recentEnrollments[] = [
                    'agent_uuid' => $agent->id,
                    'hostname' => $agent->hostname,
                    'enrolled_at' => $agent->enrolled_at->toIso8601String(),
                ];
            }

            if ($isStale && $agent->last_seen_at) {
                $recentDisconnects[] = [
                    'agent_uuid' => $agent->id,
                    'hostname' => $agent->hostname,
                    'last_seen' => $agent->last_seen_at->toIso8601String(),
                ];
            }

            if ($policyStatus === AgentPolicyStatus::UPGRADE_AVAILABLE) {
                $recentUpgrades[] = [
                    'agent_uuid' => $agent->id,
                    'hostname' => $agent->hostname,
                    'current_version' => $agent->agent_version,
                    'latest_version' => config('agent.policy.latest_agent_version', AgentConstants::AGENT_VERSION),
                ];
            }

            $totalHeartbeats += (int) ($agent->heartbeat_count ?? 0);
            $totalBytesSent += (int) ($agent->bytes_sent ?? 0);
            $totalBytesReceived += (int) ($agent->bytes_received ?? 0);
        }

        usort($errors, fn ($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));
        usort($recentDisconnects, fn ($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));

        return [
            'fleet_summary' => $fleet,
            'version_summary' => $versionCounts,
            'policy_summary' => $policyCounts,
            'gateway_summary' => $this->gatewayService->listForWorkspace($project->id),
            'capability_distribution' => $capabilityCounts,
            'top_errors' => array_slice($errors, 0, 10),
            'recent_enrollments' => array_slice($recentEnrollments, 0, 10),
            'recent_disconnects' => array_slice($recentDisconnects, 0, 10),
            'recent_upgrades' => array_slice($recentUpgrades, 0, 10),
            'heartbeat_statistics' => [
                'total_heartbeats' => $totalHeartbeats,
                'agents_reporting' => $agents->where('heartbeat_count', '>', 0)->count(),
                'avg_per_agent' => $agents->count() > 0 ? round($totalHeartbeats / $agents->count(), 1) : 0,
            ],
            'bandwidth_statistics' => [
                'bytes_sent' => $totalBytesSent,
                'bytes_received' => $totalBytesReceived,
            ],
            'current_policy' => $this->policyService->policyPayload($agents->first() ?? new Agent()),
            'generated_at' => $now->toIso8601String(),
        ];
    }
}

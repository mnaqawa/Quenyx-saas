<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentHealthLevel;
use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Constants\AgentUpdateStatus;
use App\Models\Agent;
use App\Models\AgentCertificate;
use App\Models\AgentPlugin;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Weighted operational health scoring for fleet agents.
 */
class AgentHealthScoringService
{
    /**
     * @return array{score: int, level: string, breakdown: array<string, array{weight: int, score: int, detail: string}>}
     */
    public function compute(Agent $agent): array
    {
        $weights = config('agent.health.weights', []);
        $breakdown = [];
        $totalWeight = 0;
        $weightedSum = 0;

        $factors = [
            'heartbeat_freshness' => fn () => $this->scoreHeartbeat($agent),
            'policy_sync' => fn () => $this->scorePolicy($agent),
            'plugin_health' => fn () => $this->scorePlugins($agent),
            'resource_utilization' => fn () => $this->scoreResources($agent),
            'gateway_connectivity' => fn () => $this->scoreGateway($agent),
            'version_currency' => fn () => $this->scoreVersion($agent),
            'update_status' => fn () => $this->scoreUpdate($agent),
            'certificate_status' => fn () => $this->scoreCertificate($agent),
            'capability_errors' => fn () => $this->scoreCapabilities($agent),
            'recent_failures' => fn () => $this->scoreFailures($agent),
        ];

        foreach ($factors as $key => $resolver) {
            $weight = (int) ($weights[$key] ?? 10);
            if ($weight <= 0) {
                continue;
            }
            $result = $resolver();
            $breakdown[$key] = [
                'weight' => $weight,
                'score' => $result['score'],
                'detail' => $result['detail'],
            ];
            $totalWeight += $weight;
            $weightedSum += $result['score'] * $weight;
        }

        $lifecycle = $agent->lifecycle_status ?? $agent->status;
        $hasInfo = $agent->last_seen_at !== null || $agent->enrolled_at !== null;

        if (! $hasInfo) {
            return [
                'score' => 0,
                'level' => AgentHealthLevel::UNKNOWN,
                'breakdown' => $breakdown,
            ];
        }

        $score = $totalWeight > 0 ? (int) round($weightedSum / $totalWeight) : 0;
        $level = AgentHealthLevel::fromScore($score, $lifecycle);

        if ($lifecycle === AgentLifecycleStatus::ONLINE && $score >= 80) {
            $level = AgentHealthLevel::HEALTHY;
        }

        return [
            'score' => max(0, min(100, $score)),
            'level' => $level,
            'breakdown' => $breakdown,
        ];
    }

    public function persist(Agent $agent): array
    {
        $result = $this->compute($agent);
        $agent->update([
            'health_score' => $result['score'],
            'health_level' => $result['level'],
            'health_breakdown' => $result['breakdown'],
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceSummary(Project $project): array
    {
        $agents = Agent::where('workspace_id', $project->id)->get();
        $distribution = [
            AgentHealthLevel::HEALTHY => 0,
            AgentHealthLevel::WARNING => 0,
            AgentHealthLevel::CRITICAL => 0,
            AgentHealthLevel::UNKNOWN => 0,
        ];
        $scores = [];

        foreach ($agents as $agent) {
            $level = $agent->health_level;
            if ($level === null) {
                $computed = $this->compute($agent);
                $level = $computed['level'];
            }
            $distribution[$level] = ($distribution[$level] ?? 0) + 1;
            if ($agent->health_score !== null) {
                $scores[] = $agent->health_score;
            }
        }

        return [
            'distribution' => $distribution,
            'average_score' => count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : null,
            'agents_scored' => count($scores),
            'agents_total' => $agents->count(),
        ];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreHeartbeat(Agent $agent): array
    {
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);
        if ($agent->last_seen_at === null) {
            return ['score' => 0, 'detail' => 'No heartbeat recorded'];
        }

        $minutes = $agent->last_seen_at->diffInMinutes(now());
        if ($minutes <= 2) {
            return ['score' => 100, 'detail' => 'Heartbeat fresh'];
        }
        if ($minutes <= $staleMinutes) {
            return ['score' => 80, 'detail' => "Last seen {$minutes}m ago"];
        }
        if ($minutes <= $staleMinutes * 2) {
            return ['score' => 40, 'detail' => "Stale heartbeat ({$minutes}m)"];
        }

        return ['score' => 10, 'detail' => "Disconnected ({$minutes}m)"];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scorePolicy(Agent $agent): array
    {
        $status = $agent->policy_status ?? AgentPolicyStatus::UP_TO_DATE;

        return match ($status) {
            AgentPolicyStatus::UP_TO_DATE => ['score' => 100, 'detail' => 'Policy synchronized'],
            AgentPolicyStatus::POLICY_SYNC_REQUIRED => ['score' => 50, 'detail' => 'Policy sync pending'],
            AgentPolicyStatus::POLICY_OUTDATED => ['score' => 30, 'detail' => 'Policy outdated'],
            AgentPolicyStatus::UPGRADE_AVAILABLE => ['score' => 60, 'detail' => 'Upgrade available'],
            AgentPolicyStatus::UNSUPPORTED_VERSION => ['score' => 10, 'detail' => 'Unsupported version'],
            default => ['score' => 70, 'detail' => $status],
        };
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scorePlugins(Agent $agent): array
    {
        $plugins = AgentPlugin::where('agent_id', $agent->id)->get();
        if ($plugins->isEmpty()) {
            return ['score' => 100, 'detail' => 'No plugins installed'];
        }

        $unhealthy = $plugins->where('health_status', '!=', 'healthy')->count();
        $errors = $plugins->sum('error_count');
        $ratio = 1 - ($unhealthy / max($plugins->count(), 1));

        $score = (int) round($ratio * 100);
        if ($errors > 10) {
            $score = max(0, $score - 20);
        }

        return [
            'score' => $score,
            'detail' => "{$unhealthy}/{$plugins->count()} unhealthy, {$errors} total errors",
        ];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreResources(Agent $agent): array
    {
        $queue = $agent->queue_stats ?? [];
        $dropped = (int) ($queue['dropped_events'] ?? 0);
        if ($dropped > 100) {
            return ['score' => 30, 'detail' => "{$dropped} dropped queue events"];
        }
        if ($dropped > 0) {
            return ['score' => 70, 'detail' => "{$dropped} dropped queue events"];
        }

        return ['score' => 100, 'detail' => 'Queue within limits'];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreGateway(Agent $agent): array
    {
        if (! $agent->preferred_gateway_id) {
            return ['score' => 90, 'detail' => 'Default gateway'];
        }

        $gateway = $agent->preferredGateway;
        if (! $gateway) {
            return ['score' => 50, 'detail' => 'Preferred gateway missing'];
        }

        return match ($gateway->health_status ?? 'unknown') {
            'healthy' => ['score' => 100, 'detail' => 'Gateway healthy'],
            'degraded' => ['score' => 60, 'detail' => 'Gateway degraded'],
            'unhealthy' => ['score' => 20, 'detail' => 'Gateway unhealthy'],
            default => ['score' => 70, 'detail' => 'Gateway status unknown'],
        };
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreVersion(Agent $agent): array
    {
        $latest = (string) config('agent.policy.latest_agent_version', '1.0.0');
        $current = (string) ($agent->agent_version ?? '0');

        if (version_compare($current, $latest, '>=')) {
            return ['score' => 100, 'detail' => "Version {$current} current"];
        }

        $supported = config('agent.policy.supported_agent_versions', []);
        if (in_array($current, $supported, true)) {
            return ['score' => 70, 'detail' => "Version {$current} supported but not latest"];
        }

        return ['score' => 20, 'detail' => "Version {$current} unsupported"];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreUpdate(Agent $agent): array
    {
        $status = $agent->update_status;
        if ($status === null || $status === AgentUpdateStatus::SUCCEEDED || $status === 'current') {
            return ['score' => 100, 'detail' => 'No active update'];
        }

        if (in_array($status, AgentUpdateStatus::inProgress(), true)) {
            return ['score' => 60, 'detail' => "Update in progress: {$status}"];
        }

        if ($status === AgentUpdateStatus::FAILED) {
            return ['score' => 20, 'detail' => 'Last update failed'];
        }

        return ['score' => 80, 'detail' => $status];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreCertificate(Agent $agent): array
    {
        if (! config('agent.certificates.mtls_enabled', false)) {
            return ['score' => 100, 'detail' => 'mTLS not required'];
        }

        if (! Schema::hasTable('agent_certificates')) {
            return ['score' => 50, 'detail' => 'Certificate store unavailable'];
        }

        $cert = AgentCertificate::where('agent_id', $agent->id)
            ->where('status', '!=', 'revoked')
            ->orderByDesc('expires_at')
            ->first();

        if (! $cert) {
            return ['score' => 30, 'detail' => 'No certificate issued'];
        }

        if ($cert->expires_at && $cert->expires_at->lt(now())) {
            return ['score' => 0, 'detail' => 'Certificate expired'];
        }

        if ($cert->expires_at && $cert->expires_at->lt(now()->addDays(30))) {
            return ['score' => 50, 'detail' => 'Certificate expiring soon'];
        }

        return ['score' => 100, 'detail' => 'Certificate valid'];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreCapabilities(Agent $agent): array
    {
        $caps = $agent->capabilities ?? [];
        if ($caps === []) {
            return ['score' => 80, 'detail' => 'No capabilities reported'];
        }

        return ['score' => 100, 'detail' => count($caps).' capabilities active'];
    }

    /**
     * @return array{score: int, detail: string}
     */
    private function scoreFailures(Agent $agent): array
    {
        if ($agent->last_error) {
            return ['score' => 30, 'detail' => 'Recent error: '.mb_substr($agent->last_error, 0, 80)];
        }

        $lifecycle = $agent->lifecycle_status ?? $agent->status;
        if (in_array($lifecycle, [AgentLifecycleStatus::QUARANTINED, AgentLifecycleStatus::REVOKED], true)) {
            return ['score' => 0, 'detail' => "Lifecycle: {$lifecycle}"];
        }

        return ['score' => 100, 'detail' => 'No recent failures'];
    }
}

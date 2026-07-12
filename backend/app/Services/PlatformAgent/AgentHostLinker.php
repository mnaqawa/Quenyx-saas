<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Services\DefaultMonitoringProfileService;
use Illuminate\Support\Facades\Schema;

/**
 * Links Observe target hosts to Platform Agents and converts SSH/pull metric
 * checks to push telemetry. Matching prefers agent_id, then IPs, then hostname.
 */
class AgentHostLinker
{
    /** @var list<string> */
    private const METRIC_NAMES = ['cpu', 'memory', 'disk', 'load', 'uptime'];

    public function __construct(
        private DefaultMonitoringProfileService $profiles
    ) {
    }

    /**
     * Find the best agent for a host (or null).
     */
    public function findAgentForHost(ObserveTargetHost $host): ?Agent
    {
        if ($host->agent_id) {
            $existing = Agent::query()
                ->whereKey($host->agent_id)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'revoked');
                })
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $agents = Agent::query()
            ->where('workspace_id', $host->workspace_id)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'revoked');
            })
            ->orderByDesc('last_seen_at')
            ->get();

        if ($agents->isEmpty()) {
            return null;
        }

        $hostIps = array_values(array_filter([
            trim((string) ($host->public_ip ?? '')),
            trim((string) ($host->address ?? '')),
        ], fn ($ip) => $ip !== ''));

        // 1) Match by public/private IP (most reliable when UI host name differs from OS hostname).
        foreach ($agents as $agent) {
            $agentIps = $this->agentIps($agent);
            if ($hostIps !== [] && array_intersect($hostIps, $agentIps) !== []) {
                return $agent;
            }
        }

        // 2) Match by hostname / host display name.
        $hostName = strtolower(trim((string) $host->name));
        foreach ($agents as $agent) {
            $agentHost = strtolower(trim((string) $agent->hostname));
            if ($agentHost === '' || $hostName === '') {
                continue;
            }
            if ($agentHost === $hostName
                || str_starts_with($agentHost, $hostName)
                || str_starts_with($hostName, $agentHost)
                || str_contains($agentHost, $hostName)
                || str_contains($hostName, $agentHost)
            ) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * Link host to agent (if found) and convert metric services to platform_agent telemetry.
     *
     * @return array{linked: bool, agent_id: string|null, healed_services: int}
     */
    public function linkAndHeal(ObserveTargetHost $host): array
    {
        $agent = $this->findAgentForHost($host);
        if (! $agent) {
            return ['linked' => false, 'agent_id' => null, 'healed_services' => 0];
        }

        $updates = [
            'agent_id' => $agent->id,
            'source' => 'agent',
        ];
        if (! empty($agent->public_ip) && empty($host->public_ip)) {
            $updates['public_ip'] = $agent->public_ip;
        }
        $private = $this->agentIps($agent);
        // Keep inventory private address when missing.
        if ($private !== [] && (trim((string) $host->address) === '' || $host->address === $host->public_ip)) {
            $priv = $private[0];
            foreach ($private as $ip) {
                if ($ip !== (string) $agent->public_ip) {
                    $priv = $ip;
                    break;
                }
            }
            if (trim((string) $host->address) === '') {
                $updates['address'] = $priv;
            }
        }

        $host->forceFill($updates)->save();

        $this->profiles->attachToHost($host->fresh(), (int) $host->workspace_id);
        $healed = $this->forceTelemetryOnMetricServices($host);

        return [
            'linked' => true,
            'agent_id' => (string) $agent->id,
            'healed_services' => $healed,
        ];
    }

    /**
     * Force cpu/memory/disk/load(/uptime) rows onto platform_agent telemetry.
     */
    public function forceTelemetryOnMetricServices(ObserveTargetHost $host): int
    {
        $query = ObserveTargetService::query()->where('host_id', $host->id);

        $query->where(function ($q) {
            $q->whereIn('name', self::METRIC_NAMES);
            if (Schema::hasColumn('observe_targets_services', 'service_key')) {
                $q->orWhereIn('service_key', self::METRIC_NAMES);
            }
        });

        $payload = [
            'check_command' => 'platform_agent_telemetry',
        ];
        if (Schema::hasColumn('observe_targets_services', 'check_source')) {
            $payload['check_source'] = AgentConstants::CHECK_SOURCE_PLATFORM_AGENT;
        }

        return (int) $query->update($payload);
    }

    /**
     * @return list<string>
     */
    private function agentIps(Agent $agent): array
    {
        $ips = [];
        if (is_string($agent->public_ip) && trim($agent->public_ip) !== '') {
            $ips[] = trim($agent->public_ip);
        }
        $private = $agent->private_ips;
        if (is_array($private)) {
            foreach ($private as $ip) {
                if (is_string($ip) && trim($ip) !== '') {
                    $ips[] = trim($ip);
                }
            }
        }

        return array_values(array_unique($ips));
    }
}

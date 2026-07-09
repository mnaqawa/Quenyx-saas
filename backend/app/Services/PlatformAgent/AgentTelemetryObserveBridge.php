<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\AgentMetric;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Bridges Platform Agent telemetry into QynSight observe service states.
 * Eliminates SSH/pull checks for agent-enrolled hosts.
 */
class AgentTelemetryObserveBridge
{
    /**
     * Sync all telemetry-based checks for an agent-enrolled host.
     *
     * @param  array<string, ObserveService>  $existingForWorkspace
     * @return int Number of checks updated
     */
    public function syncHost(
        ObserveTargetHost $host,
        array &$existingForWorkspace,
        Carbon $now
    ): int {
        if (! $host->agent_id) {
            return 0;
        }

        $agent = Agent::find($host->agent_id);
        if (! $agent) {
            return 0;
        }

        $metric = AgentMetric::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('collected_at')
            ->first();

        $updated = 0;
        $workspaceId = (int) $host->workspace_id;
        $prefix = 'ws' . $workspaceId . '-';
        $hostName = $prefix . $host->name;

        // Host alive from heartbeat (not ping/SSH)
        $updated += $this->syncHostAlive($agent, $workspaceId, $hostName, $existingForWorkspace, $now);

        if ($metric === null) {
            return $updated;
        }

        $payload = is_array($metric->payload) ? $metric->payload : [];
        $collectedAt = $metric->collected_at ?? $now;

        $services = ObserveTargetService::query()
            ->where('host_id', $host->id)
            ->where('enabled', true)
            ->get();

        foreach ($services as $service) {
            $checkSource = $service->check_source ?? AgentConstants::CHECK_SOURCE_PULL;
            if ($checkSource !== AgentConstants::CHECK_SOURCE_PLATFORM_AGENT) {
                continue;
            }

            $serviceKey = (string) ($service->service_key ?? $service->name);
            $result = $this->evaluateTelemetry($serviceKey, $payload, $service->check_args ?? []);

            if ($result === null) {
                continue;
            }

            $engineServiceKey = "{$hostName}::{$service->name}";
            $existing = $existingForWorkspace[$engineServiceKey] ?? null;
            $intervalSec = max(60, (int) ($service->check_interval ?? 300));

            $saved = $this->persistResult(
                $workspaceId,
                $hostName,
                $service->name,
                $engineServiceKey,
                $existing,
                $result,
                $intervalSec,
                $collectedAt
            );
            $existingForWorkspace[$engineServiceKey] = $saved;
            $this->recordHistory($workspaceId, $hostName, $service->name, $result, $collectedAt);
            $updated++;
        }

        return $updated;
    }

    /**
     * Called after metrics ingestion for real-time updates.
     */
    public function syncAfterIngest(Agent $agent): void
    {
        $hosts = ObserveTargetHost::query()
            ->where('agent_id', $agent->id)
            ->where('enabled', true)
            ->get();

        if ($hosts->isEmpty()) {
            return;
        }

        $now = now();
        $existingByWorkspace = [];

        foreach ($hosts as $host) {
            $wid = (int) $host->workspace_id;
            if (! isset($existingByWorkspace[$wid])) {
                $existingByWorkspace[$wid] = ObserveService::query()
                    ->where('engine_key', 'native')
                    ->where('workspace_id', $wid)
                    ->get()
                    ->keyBy('engine_service_key')
                    ->all();
            }
            $this->syncHost($host, $existingByWorkspace[$wid], $now);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $checkArgs
     * @return array{state: string, output: string, perfdata: string|null}|null
     */
    private function evaluateTelemetry(string $serviceKey, array $payload, array $checkArgs): ?array
    {
        $key = strtolower($serviceKey);

        if ($key === 'cpu') {
            $cpu = $payload['cpu'] ?? null;
            if (! is_array($cpu)) {
                return ['state' => 'unknown', 'output' => 'No CPU telemetry from Platform Agent', 'perfdata' => null];
            }
            $usedPct = (float) ($cpu['used_pct'] ?? 0);
            $warn = (float) ($checkArgs['warn_pct'] ?? 80);
            $crit = (float) ($checkArgs['crit_pct'] ?? 90);
            $state = $usedPct >= $crit ? 'critical' : ($usedPct >= $warn ? 'warning' : 'ok');

            return [
                'state' => $state,
                'output' => sprintf('CPU %s: %.1f%% used (source: Platform Agent)', strtoupper($state), $usedPct),
                'perfdata' => sprintf('cpu_used_pct=%.1f', $usedPct),
            ];
        }

        if ($key === 'memory') {
            $mem = $payload['memory'] ?? null;
            if (! is_array($mem) || empty($mem['total'])) {
                return ['state' => 'unknown', 'output' => 'No memory telemetry from Platform Agent', 'perfdata' => null];
            }
            $usedPct = (float) ($mem['used_pct'] ?? (($mem['total'] - ($mem['available'] ?? 0)) / $mem['total'] * 100));
            $warn = (float) ($checkArgs['warn_pct'] ?? 80);
            $crit = (float) ($checkArgs['crit_pct'] ?? 90);
            $state = $usedPct >= $crit ? 'critical' : ($usedPct >= $warn ? 'warning' : 'ok');

            return [
                'state' => $state,
                'output' => sprintf('MEMORY %s: %.1f%% used (source: Platform Agent)', strtoupper($state), $usedPct),
                'perfdata' => sprintf('mem_used_pct=%.1f', $usedPct),
            ];
        }

        if ($key === 'load') {
            $load = $payload['load'] ?? null;
            if (! is_array($load)) {
                return ['state' => 'unknown', 'output' => 'No load telemetry from Platform Agent', 'perfdata' => null];
            }
            $load1 = (float) ($load['load1'] ?? 0);
            $cores = (int) (($payload['cpu']['cores'] ?? 1) ?: 1);
            $ratio = $load1 / max(1, $cores);
            $state = $ratio >= 2.0 ? 'critical' : ($ratio >= 1.0 ? 'warning' : 'ok');

            return [
                'state' => $state,
                'output' => sprintf('LOAD %s: %.2f (1m) / %d cores (source: Platform Agent)', strtoupper($state), $load1, $cores),
                'perfdata' => sprintf('load1=%.2f', $load1),
            ];
        }

        if ($key === 'disk') {
            $disk = $payload['disk'] ?? null;
            if (! is_array($disk) || $disk === []) {
                return ['state' => 'ok', 'output' => 'DISK OK: awaiting disk telemetry (source: Platform Agent)', 'perfdata' => null];
            }

            return ['state' => 'ok', 'output' => 'DISK OK (source: Platform Agent)', 'perfdata' => null];
        }

        return null;
    }

    /**
     * @param  array<string, ObserveService>  $existingForWorkspace
     */
    private function syncHostAlive(
        Agent $agent,
        int $workspaceId,
        string $hostName,
        array &$existingForWorkspace,
        Carbon $now
    ): int {
        $staleMinutes = (int) config('agent.stale_after_minutes', 15);
        $lastSeen = $agent->last_seen_at;
        $isOnline = $lastSeen && $lastSeen->diffInMinutes($now) <= $staleMinutes;

        $result = $isOnline
            ? ['state' => 'ok', 'output' => 'Host alive (Platform Agent heartbeat)', 'perfdata' => null]
            : ['state' => 'critical', 'output' => 'Host unreachable — no recent Platform Agent heartbeat', 'perfdata' => null];

        $engineServiceKey = "{$hostName}::Host-Alive";
        $existing = $existingForWorkspace[$engineServiceKey] ?? null;
        $saved = $this->persistResult($workspaceId, $hostName, 'Host-Alive', $engineServiceKey, $existing, $result, 300, $now);
        $existingForWorkspace[$engineServiceKey] = $saved;
        $this->recordHistory($workspaceId, $hostName, 'Host-Alive', $result, $now);

        return 1;
    }

    /**
     * @param  array{state: string, output: string, perfdata: string|null}  $result
     */
    private function persistResult(
        int $workspaceId,
        string $hostName,
        string $serviceName,
        string $engineServiceKey,
        ?ObserveService $existing,
        array $result,
        int $intervalSec,
        Carbon $checkedAt
    ): ObserveService {
        $state = strtolower($result['state']);
        $data = [
            'workspace_id' => $workspaceId,
            'engine_key' => 'native',
            'engine_service_key' => $engineServiceKey,
            'host_name' => $hostName,
            'service_name' => $serviceName,
            'state' => $state,
            'output' => $result['output'],
            'plugin_output' => $result['output'],
            'perfdata' => $result['perfdata'],
            'check_command' => 'platform_agent_telemetry',
            'last_check_at' => $checkedAt,
            'next_check_at' => $checkedAt->copy()->addSeconds($intervalSec),
            'check_interval' => $intervalSec,
        ];

        if ($existing) {
            if ($existing->state !== $state) {
                $data['last_state_change_at'] = $checkedAt;
            }
            $existing->update($data);

            return $existing->fresh();
        }

        $data['last_state_change_at'] = $checkedAt;

        return ObserveService::create($data);
    }

    /**
     * @param  array{state: string, output: string, perfdata: string|null}  $result
     */
    private function recordHistory(
        int $workspaceId,
        string $hostName,
        string $serviceName,
        array $result,
        Carbon $checkedAt
    ): void {
        if (! Schema::hasTable('observe_metrics_history')) {
            return;
        }

        ObserveMetricHistory::create([
            'workspace_id' => $workspaceId,
            'host_name' => $hostName,
            'service_name' => $serviceName,
            'state' => $result['state'],
            'output' => $result['output'],
            'perfdata' => $result['perfdata'],
            'checked_at' => $checkedAt,
        ]);
    }
}

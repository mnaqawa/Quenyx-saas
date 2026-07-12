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

        if (! $host->isMonitoringAllowed()) {
            return 0;
        }

        $agent = Agent::find($host->agent_id);
        if (! $agent) {
            return 0;
        }

        // Ensure standard metric services are platform_agent (heals SSH/pull leftovers).
        app(\App\Services\DefaultMonitoringProfileService::class)->attachToHost($host, (int) $host->workspace_id);

        $metric = AgentMetric::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('collected_at')
            ->first();

        $updated = 0;
        $workspaceId = (int) $host->workspace_id;
        $prefix = 'ws' . $workspaceId . '-';
        $hostName = $prefix . $host->name;

        $staleSeconds = $this->staleAfterSeconds();
        $contactAt = $this->agentFreshnessAt($agent, $metric);
        $hostAliveFresh = $contactAt !== null && $contactAt->diffInSeconds($now) <= $staleSeconds;

        // Host alive from heartbeat and/or recent telemetry (not SSH/ping).
        $updated += $this->syncHostAlive(
            $agent,
            $workspaceId,
            $hostName,
            $existingForWorkspace,
            $now,
            $hostAliveFresh,
            $contactAt
        );

        if ($metric === null) {
            app(\App\Services\PlatformAgent\HostLifecycleService::class)
                ->updateHealthFromTelemetry($host, $hostAliveFresh ? 'ok' : 'critical');

            return $updated;
        }

        $payload = is_array($metric->payload) ? $metric->payload : [];
        $collectedAt = $metric->collected_at ?? $now;
        $metricAgeSec = $collectedAt->diffInSeconds($now);
        $metricsFresh = $metricAgeSec <= $staleSeconds;

        $services = ObserveTargetService::query()
            ->where('host_id', $host->id)
            ->where('enabled', true)
            ->get();

        $worst = $hostAliveFresh ? 'ok' : 'critical';

        foreach ($services as $service) {
            $serviceKey = (string) ($service->service_key ?? $service->name);
            $checkSource = $service->check_source ?? AgentConstants::CHECK_SOURCE_PULL;
            $isAgentMetric = in_array(strtolower($serviceKey), ['cpu', 'memory', 'disk', 'load', 'uptime'], true)
                || in_array(strtolower((string) $service->name), ['cpu', 'memory', 'disk', 'load', 'uptime'], true);

            // Agent hosts must never use SSH/pull for standard metrics — heal + evaluate from telemetry.
            if ($checkSource !== AgentConstants::CHECK_SOURCE_PLATFORM_AGENT) {
                if (! $isAgentMetric) {
                    continue;
                }
                if (Schema::hasColumn('observe_targets_services', 'check_source')) {
                    $service->forceFill([
                        'check_source' => AgentConstants::CHECK_SOURCE_PLATFORM_AGENT,
                        'check_command' => 'platform_agent_telemetry',
                    ])->save();
                }
            }

            $result = $this->evaluateTelemetry($serviceKey, $payload, $service->check_args ?? []);

            if ($result === null) {
                continue;
            }

            // Do not keep green OK when the metric sample itself is stale.
            if (! $metricsFresh && in_array(($result['state'] ?? ''), ['ok', 'warning'], true)) {
                $ageMin = (int) ceil($metricAgeSec / 60);
                $result = [
                    'state' => 'warning',
                    'output' => sprintf(
                        'Stale Platform Agent telemetry (%d min old) — last sample %s. Check that quenyx-agent is running and can reach the gateway.',
                        $ageMin,
                        $collectedAt->toIso8601String()
                    ),
                    'perfdata' => $result['perfdata'] ?? null,
                ];
            }

            // Annotate with reachable address so UI does not look like private-IP SSH checks.
            $reach = $host->reachableAddress();
            if ($reach !== '' && is_string($result['output'] ?? null) && ! str_contains((string) $result['output'], $reach)) {
                $result['output'] = rtrim((string) $result['output']).' ('.$reach.')';
            }

            $engineServiceKey = "{$hostName}::{$service->name}";
            $existing = $existingForWorkspace[$engineServiceKey] ?? null;
            $intervalSec = max(60, (int) ($service->check_interval ?? 300));

            // Fresh samples keep collected_at; stale samples bump last_check so UI age is honest.
            $checkedAt = $metricsFresh ? $collectedAt : $now;

            $saved = $this->persistResult(
                $workspaceId,
                $hostName,
                $service->name,
                $engineServiceKey,
                $existing,
                $result,
                $intervalSec,
                $checkedAt
            );
            $existingForWorkspace[$engineServiceKey] = $saved;
            $updated++;

            $state = strtolower((string) ($result['state'] ?? 'ok'));
            if ($state === 'critical') {
                $worst = 'critical';
            } elseif ($state === 'warning' && $worst !== 'critical') {
                $worst = 'warning';
            } elseif (in_array($state, ['unknown', 'pending'], true) && $worst === 'ok') {
                $worst = 'pending';
            }
        }

        if (! $hostAliveFresh) {
            $worst = 'critical';
        }

        app(\App\Services\PlatformAgent\HostLifecycleService::class)->updateHealthFromTelemetry($host, $worst);

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
                return [
                    'state' => 'unknown',
                    'output' => 'No disk telemetry from Platform Agent yet',
                    'perfdata' => null,
                ];
            }

            // Prefer root mount when present; otherwise first entry.
            $entry = $disk['/'] ?? $disk['root'] ?? null;
            if (! is_array($entry)) {
                $first = reset($disk);
                $entry = is_array($first) ? $first : null;
            }
            if (! is_array($entry)) {
                return [
                    'state' => 'unknown',
                    'output' => 'Disk telemetry present but unrecognized format',
                    'perfdata' => null,
                ];
            }

            $usedPct = isset($entry['used_pct'])
                ? (float) $entry['used_pct']
                : (isset($entry['total'], $entry['used']) && (float) $entry['total'] > 0
                    ? ((float) $entry['used'] / (float) $entry['total']) * 100
                    : null);
            $freePct = isset($entry['free_pct'])
                ? (float) $entry['free_pct']
                : ($usedPct !== null ? max(0, 100 - $usedPct) : null);

            if ($freePct === null && $usedPct === null) {
                return ['state' => 'ok', 'output' => 'DISK OK (source: Platform Agent)', 'perfdata' => null];
            }

            // check_args warn/crit are free-space percentages (same as classic check_disk).
            $warnFree = (float) ($checkArgs['warn_pct'] ?? 20);
            $critFree = (float) ($checkArgs['crit_pct'] ?? 10);
            $free = $freePct ?? max(0, 100 - (float) $usedPct);
            $state = $free <= $critFree ? 'critical' : ($free <= $warnFree ? 'warning' : 'ok');

            return [
                'state' => $state,
                'output' => sprintf('DISK %s: %.1f%% free (source: Platform Agent)', strtoupper($state), $free),
                'perfdata' => sprintf('disk_free_pct=%.1f', $free),
            ];
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
        Carbon $now,
        bool $isFresh,
        ?Carbon $freshnessAt
    ): int {
        if ($isFresh) {
            $ageSec = $freshnessAt ? $freshnessAt->diffInSeconds($now) : 0;
            $result = [
                'state' => 'ok',
                'output' => sprintf(
                    'Host alive (Platform Agent) — last contact %ds ago',
                    $ageSec
                ),
                'perfdata' => null,
            ];
        } else {
            $lastSeen = $agent->last_seen_at;
            $detail = $lastSeen
                ? sprintf('last heartbeat %s', $lastSeen->toIso8601String())
                : 'no heartbeat recorded';
            $result = [
                'state' => 'critical',
                'output' => 'Host unreachable — no recent Platform Agent heartbeat ('.$detail.'). Ensure quenyx-agent is running and can reach '.(\App\Support\AgentGateway::url()).'.',
                'perfdata' => null,
            ];
        }

        $engineServiceKey = "{$hostName}::Host-Alive";
        $existing = $existingForWorkspace[$engineServiceKey] ?? null;
        $saved = $this->persistResult($workspaceId, $hostName, 'Host-Alive', $engineServiceKey, $existing, $result, 300, $now);
        $existingForWorkspace[$engineServiceKey] = $saved;

        return 1;
    }

    private function staleAfterSeconds(): int
    {
        $minutes = max(1, (int) config('agent.stale_after_minutes', 15));
        // Allow at least 3× default heartbeat interval so a single missed beat is not CRITICAL.
        $heartbeat = max(60, (int) config('agent.configuration.defaults.heartbeat_interval_seconds', 300));

        return max($minutes * 60, $heartbeat * 3);
    }

    private function agentFreshnessAt(Agent $agent, ?AgentMetric $metric): ?Carbon
    {
        $candidates = [];
        if ($agent->last_seen_at) {
            $candidates[] = $agent->last_seen_at;
        }
        if ($metric?->collected_at) {
            $candidates[] = $metric->collected_at;
        }
        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sortByDesc(fn (Carbon $c) => $c->timestamp)->first();
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

        $perfdata = trim((string) ($result['perfdata'] ?? ''));
        if ($perfdata === '') {
            return;
        }

        try {
            foreach (preg_split('/\s+/', $perfdata) ?: [] as $part) {
                if (! str_contains($part, '=')) {
                    continue;
                }

                [$metric, $value] = explode('=', $part, 2);
                $metric = trim($metric);
                if ($metric === '') {
                    continue;
                }

                ObserveMetricHistory::create([
                    'workspace_id' => $workspaceId,
                    'host_name' => $hostName,
                    'service_name' => $serviceName,
                    'metric' => $metric,
                    'value' => (float) $value,
                    'recorded_at' => $checkedAt,
                ]);
            }
        } catch (\Throwable) {
            // Metric history is optional; telemetry state must still update.
        }
    }
}

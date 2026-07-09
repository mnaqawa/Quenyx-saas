<?php

namespace App\Services\AI;

use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Constants\HostLifecycleStatus;
use App\Models\Agent;
use App\Models\AgentMetric;
use App\Models\AgentPlugin;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use App\Services\PlatformAgent\FleetDashboardService;
use Illuminate\Support\Carbon;

/**
 * Builds a compact, factual telemetry snapshot for a workspace that is injected
 * into the AI system prompt. Only real data is included; nothing is fabricated.
 */
class AgentContextBuilder
{
    public function __construct(
        private readonly FleetDashboardService $fleetDashboard,
    ) {}

    /**
     * @return string Human-readable context block (bounded in size).
     */
    public function build(Project $project, ?string $host = null): string
    {
        $maxHosts = (int) config('ai.context_max_hosts', 25);
        $metricChars = (int) config('ai.context_metric_chars', 1500);

        $agents = Agent::where('workspace_id', $project->id)
            ->orderByDesc('last_seen_at')
            ->limit($maxHosts)
            ->get();

        $hostCount = ObserveTargetHost::where('workspace_id', $project->id)->count();
        $serviceCount = ObserveTargetService::where('workspace_id', $project->id)
            ->where('enabled', true)
            ->count();

        $latestByAgent = $this->latestMetrics($agents->pluck('id')->all());
        $fleet = $this->fleetDashboard->build($project);

        $lines = [];
        $lines[] = '=== WORKSPACE TELEMETRY (live, do not invent beyond this) ===';
        $lines[] = sprintf('Workspace: "%s" (id %d)', $project->name, $project->id);
        $lines[] = sprintf('Generated at: %s UTC', now()->toDateTimeString());
        $lines[] = sprintf(
            'Inventory: %d monitored host(s), %d enabled service(s), %d enrolled agent(s).',
            $hostCount,
            $serviceCount,
            $agents->count(),
        );

        $fs = $fleet['fleet_summary'] ?? [];
        $lines[] = sprintf(
            'Fleet: total=%d online=%d offline=%d outdated=%d quarantined=%d maintenance=%d enrollment_pending=%d.',
            $fs['total'] ?? 0,
            $fs['online'] ?? 0,
            $fs['offline'] ?? 0,
            $fs['outdated'] ?? 0,
            $fs['quarantined'] ?? 0,
            $fs['maintenance'] ?? 0,
            $fs['enrollment_pending'] ?? 0,
        );

        if ($host) {
            $lines[] = sprintf('Focus host requested by user: "%s".', $host);
        }

        if ($agents->isEmpty()) {
            $lines[] = 'No agents are enrolled in this workspace, so no live host metrics are available.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '--- Platform Agents ---';

        foreach ($agents as $agent) {
            $metric = $latestByAgent[$agent->id] ?? null;
            $lastSeen = $agent->last_seen_at ? $agent->last_seen_at->diffForHumans() : 'never';
            $lifecycle = $agent->lifecycle_status ?? $agent->status ?? AgentLifecycleStatus::OFFLINE;
            $policyStatus = $agent->policy_status ?? AgentPolicyStatus::UP_TO_DATE;

            $lines[] = sprintf(
                '- %s [%s/%s] status=%s lifecycle=%s policy=%s last_seen=%s',
                $agent->hostname,
                $agent->os ?: 'unknown-os',
                $agent->arch ?: 'unknown-arch',
                $agent->status ?: 'offline',
                $lifecycle,
                $policyStatus,
                $lastSeen,
            );

            if ($agent->last_error) {
                $lines[] = '    last_error: '.$agent->last_error;
            }

            $pluginSummary = AgentPlugin::where('agent_id', $agent->id)
                ->get(['plugin_key', 'health_status', 'error_count'])
                ->map(fn (AgentPlugin $p) => $p->plugin_key.'='.$p->health_status.'(errors:'.$p->error_count.')')
                ->implode(', ');
            if ($pluginSummary !== '') {
                $lines[] = '    plugins: '.$pluginSummary;
            }

            if (! $metric) {
                $lines[] = '    metrics: none reported yet';
                continue;
            }

            $collectedAt = $metric->collected_at instanceof Carbon
                ? $metric->collected_at->toDateTimeString().' UTC'
                : (string) $metric->collected_at;
            $lines[] = '    collected_at: '.$collectedAt;

            $summary = $this->summarizeMetricPayload($metric->payload ?? []);
            if ($summary !== '') {
                $lines[] = '    key metrics: '.$summary;
            }

            $raw = $this->truncateJson($metric->payload ?? [], $metricChars);
            $lines[] = '    raw: '.$raw;
        }

        if ($host) {
            $target = ObserveTargetHost::where('workspace_id', $project->id)
                ->where(function ($q) use ($host) {
                    $q->where('name', $host)->orWhere('address', $host);
                })
                ->first();
            if ($target) {
                $lines[] = '';
                $lines[] = '--- Focus Host Diagnostics ---';
                $lines[] = sprintf(
                    'Host %s lifecycle=%s (%s) enabled=%s agent_id=%s',
                    $target->name,
                    $target->lifecycle_status ?? HostLifecycleStatus::ACTIVE,
                    HostLifecycleStatus::displayLabel($target->lifecycle_status, $target->lifecycle_reason),
                    $target->enabled ? 'yes' : 'no',
                    $target->agent_id ?? 'none',
                );
            }
        }

        $recentDisconnects = $fleet['recent_disconnects'] ?? [];
        if ($recentDisconnects !== []) {
            $lines[] = '';
            $lines[] = '--- Recent Agent Disconnects ---';
            foreach (array_slice($recentDisconnects, 0, 5) as $row) {
                $lines[] = sprintf('- %s last_seen=%s', $row['hostname'] ?? '?', $row['last_seen'] ?? '?');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Latest metric row per agent id, keyed by agent id.
     *
     * @param array<int, string> $agentIds
     * @return array<string, AgentMetric>
     */
    private function latestMetrics(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        $rows = AgentMetric::whereIn('agent_id', $agentIds)
            ->orderByDesc('collected_at')
            ->limit(max(count($agentIds) * 3, 30))
            ->get();

        $byAgent = [];
        foreach ($rows as $row) {
            if (! isset($byAgent[$row->agent_id])) {
                $byAgent[$row->agent_id] = $row;
            }
        }

        return $byAgent;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeMetricPayload(array $payload): string
    {
        $parts = [];

        foreach (['cpu' => '%', 'memory' => '%', 'mem' => '%', 'disk' => '%', 'load' => '', 'network' => '%'] as $key => $unit) {
            $value = $this->extractNumeric($payload, $key);
            if ($value !== null) {
                $parts[] = sprintf('%s=%s%s', $key, $value, $unit);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractNumeric(array $payload, string $key): ?float
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        $node = $payload[$key];

        if (is_numeric($node)) {
            return round((float) $node, 2);
        }

        if (is_array($node)) {
            foreach (['value', 'percent', 'usage', 'used_percent'] as $sub) {
                if (isset($node[$sub]) && is_numeric($node[$sub])) {
                    return round((float) $node[$sub], 2);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function truncateJson(array $payload, int $maxChars): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{}';
        }

        if (mb_strlen($json) <= $maxChars) {
            return $json;
        }

        return mb_substr($json, 0, $maxChars).'…(truncated)';
    }
}

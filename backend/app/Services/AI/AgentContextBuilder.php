<?php

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AgentMetric;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Builds a compact, factual telemetry snapshot for a workspace that is injected
 * into the AI system prompt. Only real data is included; nothing is fabricated.
 */
class AgentContextBuilder
{
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

        if ($host) {
            $lines[] = sprintf('Focus host requested by user: "%s".', $host);
        }

        if ($agents->isEmpty()) {
            $lines[] = 'No agents are enrolled in this workspace, so no live host metrics are available.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '--- Hosts ---';

        foreach ($agents as $agent) {
            $metric = $latestByAgent[$agent->id] ?? null;
            $lastSeen = $agent->last_seen_at ? $agent->last_seen_at->diffForHumans() : 'never';

            $lines[] = sprintf(
                '- %s [%s/%s] status=%s, last_seen=%s',
                $agent->hostname,
                $agent->os ?: 'unknown-os',
                $agent->arch ?: 'unknown-arch',
                $agent->status ?: 'unknown',
                $lastSeen,
            );

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

        // Pull the most recent rows for these agents, then keep the first
        // (latest) per agent. Bounded by agent count so this stays cheap.
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
     * Pull commonly-used scalar metrics from a heterogeneous payload, defensively.
     *
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
     * Find a numeric value for a metric key whether it is stored flat
     * (e.g. {"cpu": 55}) or nested (e.g. {"cpu": {"value": 55}}).
     *
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

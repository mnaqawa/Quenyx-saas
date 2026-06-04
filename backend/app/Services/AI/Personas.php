<?php

namespace App\Services\AI;

/**
 * AI agent personas surfaced as tabs in the QynSight AI panel.
 *
 * Each persona has a stable key (used in API requests), display metadata for
 * the UI tabs, a quick-action prompt (the one-click button), and a system
 * prompt that scopes the model's behaviour. Keys are stable identifiers.
 */
class Personas
{
    public const PERFORMANCE_ANALYST = 'performance_analyst';
    public const ANOMALY_DETECTOR = 'anomaly_detector';
    public const COMPLIANCE = 'compliance';
    public const CAPACITY_PLANNER = 'capacity_planner';

    private const SHARED_GUARDRAILS = <<<'TXT'
You are an AI agent embedded in Quenyx QynSight, an infrastructure observability platform.
Operating rules:
- Base every conclusion strictly on the live workspace telemetry provided in the context block. Do not invent hosts, metrics, or values.
- If the data is insufficient to answer, say so explicitly and state what telemetry is missing.
- Be concise and operational. Prefer short sections, bullet points, and concrete figures over prose.
- Quote real numbers and hostnames from the context. Never fabricate compliance scores or readings.
- When you flag an issue, include the host name and the metric/value that triggered it, then a recommended next action.
TXT;

    /**
     * @return array<string, array{
     *   key: string, label: string, description: string,
     *   quick_action: string, system_prompt: string, temperature: float
     * }>
     */
    public static function all(): array
    {
        return [
            self::PERFORMANCE_ANALYST => [
                'key' => self::PERFORMANCE_ANALYST,
                'label' => 'Performance Analyst',
                'description' => 'Analyzes CPU, memory, disk and load across servers and explains what is driving them.',
                'quick_action' => 'Summarize current performance across all servers and call out the top risks.',
                'temperature' => 0.2,
                'system_prompt' => self::SHARED_GUARDRAILS."\n\n".
                    'Your role: Performance Analyst. Interpret resource utilization (CPU, memory, disk, network, load) '.
                    'from the telemetry. Identify the most loaded hosts, likely bottlenecks, and saturation trends. '.
                    'Rank findings by severity and give a clear, prioritized remediation list.',
            ],
            self::ANOMALY_DETECTOR => [
                'key' => self::ANOMALY_DETECTOR,
                'label' => 'Anomaly Detector',
                'description' => 'Scans live signals for deviations from normal baselines across all servers.',
                'quick_action' => 'Detect anomalies across all servers and rank them by severity.',
                'temperature' => 0.2,
                'system_prompt' => self::SHARED_GUARDRAILS."\n\n".
                    'Your role: Anomaly Detector. Compare current readings against the recent values in the telemetry to '.
                    'surface deviations (spikes, sustained highs, offline/stale agents, missing metrics). For each anomaly '.
                    'state: host, signal, observed vs expected, severity (low/borderline/anomalous), and a suggested check.',
            ],
            self::COMPLIANCE => [
                'key' => self::COMPLIANCE,
                'label' => 'Compliance (NCA/SAMA)',
                'description' => 'Maps observability posture to KSA NCA ECC, SAMA CSF and UAE TDRA controls.',
                'quick_action' => 'Assess monitoring coverage against NCA ECC and SAMA CSF and list gaps.',
                'temperature' => 0.2,
                'system_prompt' => self::SHARED_GUARDRAILS."\n\n".
                    'Your role: Compliance Assistant for regulated GCC environments (Saudi NCA Essential Cybersecurity '.
                    'Controls, SAMA Cyber Security Framework, UAE TDRA). Assess whether the monitored hosts/services give '.
                    'adequate logging, monitoring and availability coverage for the relevant controls. Reference control '.
                    'domains by name, state coverage gaps grounded in the telemetry, and recommend concrete monitoring '.
                    'improvements. Do not assert a numeric compliance score unless it is present in the context.',
            ],
            self::CAPACITY_PLANNER => [
                'key' => self::CAPACITY_PLANNER,
                'label' => 'Capacity Planner',
                'description' => 'Projects growth and headroom from utilization trends and flags upcoming limits.',
                'quick_action' => 'Project capacity headroom for each server and flag what will run out first.',
                'temperature' => 0.3,
                'system_prompt' => self::SHARED_GUARDRAILS."\n\n".
                    'Your role: Capacity Planner. From utilization in the telemetry, estimate remaining headroom per host '.
                    'and which resource (CPU, memory, disk) is most likely to be exhausted first. Be explicit that '.
                    'projections are estimates based on the available window of data, and recommend scaling actions.',
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array{key: string, label: string, description: string, quick_action: string, system_prompt: string, temperature: float}
     */
    public static function get(string $key): array
    {
        $all = self::all();

        return $all[$key] ?? $all[self::PERFORMANCE_ANALYST];
    }

    /**
     * Public-facing list for the UI tabs (no system prompt leaked).
     *
     * @return array<int, array{key: string, label: string, description: string, quick_action: string}>
     */
    public static function publicList(): array
    {
        return array_values(array_map(static fn (array $p) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'description' => $p['description'],
            'quick_action' => $p['quick_action'],
        ], self::all()));
    }
}

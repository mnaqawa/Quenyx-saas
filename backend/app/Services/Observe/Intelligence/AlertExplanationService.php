<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveAlertEvent;
use App\Models\ObserveService;
use App\Models\Project;
use App\Models\User;

/**
 * Sprint 21 — Alert Intelligence (Explain / Investigate).
 *
 * Turns an alert into an explainable artifact: deterministic operational impact, most-likely causes
 * (from {@see RootCauseService}), the evidence used, related alerts, evidence-based suggested
 * actions, and a confidence that is reported ONLY when derived from real evidence. The AI layer
 * narrates this — it never invents the causes, the numbers, or the confidence.
 */
class AlertExplanationService
{
    /** Evidence-based remediation hints per resource layer. */
    private const ACTIONS = [
        'cpu' => ['Investigate top CPU-consuming processes on the host', 'Distribute workload or scale vCPU capacity'],
        'memory' => ['Check for memory leaks or high-memory processes', 'Increase RAM or tune service memory limits'],
        'disk' => ['Reclaim or expand storage on the affected volume', 'Review log/data growth and retention policy'],
        'network' => ['Inspect network saturation, errors, and upstream links', 'Review bandwidth allocation'],
        'service' => ['Inspect the failing service check output and logs', 'Restart or remediate the affected service'],
    ];

    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
        private readonly RootCauseService $rootCause,
        private readonly IncidentTimelineService $timeline,
        private readonly OperationsAiAnalyst $analyst,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function explain(Project $project, ?User $user, ObserveAlertEvent $event): array
    {
        $evidence = $this->evidence->alertEvidence($project, $event);
        $rca = $this->rootCause->analyze($this->signals($project, $event, $evidence));

        $deterministic = [
            'operational_impact' => $this->impact($event, $rca),
            'most_likely_causes' => $this->likelyCauses($rca),
            'evidence_used' => $this->evidenceRefs($evidence),
            'related_alerts' => $evidence['related_alerts'],
            'suggested_actions' => $this->suggestedActions($rca),
            'confidence' => $rca['root_cause'] !== null ? $rca['confidence'] : null,
            'root_cause' => $rca['root_cause'],
        ];

        $question = sprintf(
            'Explain alert "%s" (severity: %s) on host %s. Summarize the operational impact, the most likely cause, and what to do next, using only the evidence.',
            $event->title,
            $event->severity,
            $evidence['alert']['host'] ?? 'unknown'
        );

        $ai = $this->analyst->narrate(
            $project,
            $user,
            'ops_alert_explanation',
            ['alert_evidence' => $evidence, 'root_cause_analysis' => $rca, 'deterministic' => $deterministic],
            $question,
            'alert_explain',
            'qynsight.intelligence.alerts.explain',
            'text',
            $this->evidenceRefs($evidence),
        );

        return array_merge($deterministic, ['ai_explanation' => $ai]);
    }

    /**
     * @return array<string, mixed>
     */
    public function investigate(Project $project, ?User $user, ObserveAlertEvent $event): array
    {
        $evidence = $this->evidence->alertEvidence($project, $event);
        $rca = $this->rootCause->analyze($this->signals($project, $event, $evidence));
        $timeline = $this->timeline->build($project, $event);

        $deterministic = [
            'operational_impact' => $this->impact($event, $rca),
            'most_likely_causes' => $this->likelyCauses($rca),
            'root_cause' => $rca['root_cause'],
            'causal_chain' => $rca['chain'],
            'timeline' => $timeline,
            'evidence_used' => $this->evidenceRefs($evidence),
            'related_alerts' => $evidence['related_alerts'],
            'suggested_actions' => $this->suggestedActions($rca),
            'confidence' => $rca['root_cause'] !== null ? $rca['confidence'] : null,
        ];

        $question = sprintf(
            'Investigate alert "%s" on host %s. Walk through the causal chain (CPU→Memory→Storage→Network→Service), identify the most probable root cause, reference the timeline, and recommend remediation — using only the evidence.',
            $event->title,
            $evidence['alert']['host'] ?? 'unknown'
        );

        $ai = $this->analyst->narrate(
            $project,
            $user,
            'ops_alert_investigation',
            ['alert_evidence' => $evidence, 'root_cause_analysis' => $rca, 'timeline' => $timeline, 'deterministic' => $deterministic],
            $question,
            'alert_investigate',
            'qynsight.intelligence.alerts.investigate',
            'text',
            $this->evidenceRefs($evidence),
        );

        return array_merge($deterministic, ['ai_investigation' => $ai]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function signals(Project $project, ObserveAlertEvent $event, array $evidence): array
    {
        $services = [];
        if ($event->host_name !== null) {
            $services = ObserveService::query()
                ->where('workspace_id', $project->id)
                ->where('engine_key', 'native')
                ->where('host_name', $event->host_name)
                ->get(['service_name', 'state'])
                ->map(fn (ObserveService $s): array => ['name' => (string) $s->service_name, 'state' => (string) $s->state])
                ->all();
        }

        return [
            'latest_metrics' => $evidence['host']['latest_metrics'] ?? [],
            'recent_metrics' => $evidence['recent_metrics'] ?? [],
            'services' => $services,
            'alerts' => array_merge([$evidence['alert']], $evidence['related_alerts']),
        ];
    }

    /**
     * @param  array<string, mixed>  $rca
     * @return array<string, mixed>
     */
    private function impact(ObserveAlertEvent $event, array $rca): array
    {
        $criticalLayers = array_values(array_filter($rca['chain'], fn ($c): bool => ($c['state'] ?? 'ok') === 'critical'));

        return [
            'severity' => (string) $event->severity,
            'status' => (string) $event->status,
            'occurrence_count' => (int) $event->occurrence_count,
            'degraded_layers' => count($criticalLayers),
            'summary' => sprintf(
                '%s severity alert%s affecting %d resource layer(s).',
                ucfirst((string) $event->severity),
                $event->occurrence_count > 1 ? sprintf(' (recurred %d times)', $event->occurrence_count) : '',
                count($criticalLayers)
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $rca
     * @return list<array<string, mixed>>
     */
    private function likelyCauses(array $rca): array
    {
        $causes = array_values(array_filter($rca['chain'], fn ($c): bool => ($c['pressure'] ?? 0) > 0));

        return array_map(fn ($c): array => [
            'layer' => $c['layer'],
            'state' => $c['state'],
            'observed_value' => $c['observed_value'] ?? null,
            'pressure' => $c['pressure'],
        ], array_slice($causes, 0, 3));
    }

    /**
     * @param  array<string, mixed>  $rca
     * @return list<string>
     */
    private function suggestedActions(array $rca): array
    {
        $layer = $rca['root_cause']['layer'] ?? null;
        if ($layer === null) {
            return ['Collect more monitoring data — current evidence is insufficient for a specific recommendation.'];
        }

        return self::ACTIONS[$layer] ?? ['Review the affected resource and related service checks.'];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function evidenceRefs(array $evidence): array
    {
        $refs = [];
        $alert = $evidence['alert'] ?? [];
        if ($alert !== []) {
            $refs[] = [
                'source_document_key' => 'qynsight.alert.'.($alert['uuid'] ?? ''),
                'official_reference' => 'Alert: '.($alert['title'] ?? ''),
                'type' => 'alert',
            ];
        }
        if (($evidence['host'] ?? null) !== null) {
            $refs[] = [
                'source_document_key' => 'qynsight.host.'.($evidence['host']['uuid'] ?? ''),
                'official_reference' => 'Host: '.($evidence['host']['name'] ?? ''),
                'type' => 'host_metrics',
            ];
        }
        foreach (($evidence['recent_metrics'] ?? []) as $metric => $data) {
            if ($data['available'] ?? false) {
                $refs[] = [
                    'source_document_key' => 'qynsight.metric.'.$metric,
                    'official_reference' => sprintf('%s trend (last %.1f%%, avg %.1f%%)', strtoupper((string) $metric), (float) $data['last'], (float) $data['avg']),
                    'type' => 'metric',
                ];
            }
        }

        return $refs;
    }
}

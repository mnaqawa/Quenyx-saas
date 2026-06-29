<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\AuditLog;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Support\Observe\OperationsEntityId;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 21 — Operations Intelligence orchestrator.
 *
 * Aggregates the Operations Intelligence dashboard (overview), runs the Monitoring Copilot (reusing
 * the shared AI conversation surface), and produces Service Health Intelligence + evidence-based
 * Operational Recommendations. All numbers come from QynSight; the AI layer only narrates.
 */
class OperationsIntelligenceService
{
    private const THRESHOLDS = [
        'cpu' => [70.0, 90.0],
        'memory' => [80.0, 95.0],
        'disk' => [85.0, 95.0],
        'network' => [70.0, 90.0],
    ];

    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
        private readonly OperationsAiAnalyst $analyst,
        private readonly RootCauseService $rootCause,
        private readonly CapacityAdvisorService $capacityAdvisor,
        private readonly PerformanceAdvisorService $performance,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * Operations Intelligence dashboard — real data only.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        $health = $this->evidence->infrastructureHealth($project);
        $openAlerts = $this->evidence->openAlerts($project, 24, 25);
        $capacity = $this->evidence->capacity($project, '30d');
        $performance = $this->performance->analyze($project, null);
        $recommendations = $this->recommendations($project);

        return [
            'infrastructure_health' => $health,
            'open_alerts' => array_slice($openAlerts, 0, 10),
            'open_alert_count' => count($openAlerts),
            'critical_services' => $this->criticalServices($project),
            'top_operational_risks' => $this->topRisks($health, $performance, $openAlerts),
            'predicted_capacity_risks' => $this->capacityRisks($capacity),
            'recent_recommendations' => array_slice($recommendations, 0, 8),
            'recent_ai_investigations' => $this->recentInvestigations($project),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Monitoring Copilot — reuses the shared AI conversation surface (creates/continues a real
     * Quenyx AI conversation) and grounds answers in current operational evidence.
     *
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        $evidence = [
            'infrastructure_health' => $this->evidence->infrastructureHealth($project),
            'open_alerts' => $this->evidence->openAlerts($project, 24, 30),
            'capacity_summary' => $this->capacitySummary($this->evidence->capacity($project, '30d')),
            'recent_changes' => $this->evidence->changes($project, 24),
            'performance' => $this->performance->analyze($project, null),
        ];

        $ai = $this->analyst->narrate(
            $project,
            $user,
            'ops_copilot',
            $evidence,
            $question,
            'copilot',
            'qynsight.intelligence.copilot',
            'text',
            $this->overviewCitations($evidence),
        );

        // Reuse the shared AI conversation surface so copilot threads appear in Quenyx AI.
        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null
            ? $this->conversations->findForProject($project, $conversationUuid)
            : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Operations Copilot',
                'origin' => 'qynsight_operations_intelligence',
            ]);
        }

        $promptLogging = (bool) config('ai.feature_flags.prompt_logging', false);
        $this->conversations->recordMessage($conversation, 'user', $promptLogging ? $question : null, new AiUsage(), (bool) ($ai['mocked'] ?? false));
        $assistant = $this->conversations->recordMessage(
            $conversation,
            'assistant',
            $promptLogging ? ($ai['content'] ?? null) : null,
            new AiUsage(
                (int) ($ai['usage']['prompt_tokens'] ?? 0),
                (int) ($ai['usage']['completion_tokens'] ?? 0),
                (int) ($ai['usage']['total_tokens'] ?? 0),
            ),
            (bool) ($ai['mocked'] ?? false),
        );

        return [
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $assistant->uuid,
            'answer' => $ai,
            'evidence' => $evidence,
        ];
    }

    /**
     * Service Health Intelligence — explains a host's health (why, what changed, impact, action).
     *
     * @return array<string, mixed>
     */
    public function explainHost(Project $project, ?User $user, ObserveTargetHost $host): array
    {
        $evidence = $this->evidence->hostEvidence($project, $host);
        $rca = $this->rootCause->analyze([
            'latest_metrics' => $evidence['host']['latest_metrics'] ?? [],
            'recent_metrics' => $evidence['recent_metrics'] ?? [],
            'services' => array_map(fn ($s): array => ['name' => $s['name'], 'state' => $s['state']], $evidence['services'] ?? []),
            'alerts' => $evidence['open_alerts'] ?? [],
        ]);

        $question = sprintf(
            'Explain the current health of host %s: why is it in this state, what changed, the expected impact, and the suggested action — using only the evidence.',
            $host->name
        );

        $ai = $this->analyst->narrate(
            $project,
            $user,
            'ops_host_health',
            ['host_evidence' => $evidence, 'root_cause_analysis' => $rca],
            $question,
            'host_explain',
            'qynsight.intelligence.hosts.explain',
            'text',
            $this->hostCitations($evidence),
        );

        return [
            'host' => $evidence['host'],
            'root_cause' => $rca['root_cause'],
            'causal_chain' => $rca['chain'],
            'confidence' => $rca['root_cause'] !== null ? $rca['confidence'] : null,
            'open_alerts' => $evidence['open_alerts'],
            'ai_explanation' => $ai,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeService(Project $project, ?User $user, ObserveService $service): array
    {
        $evidence = $this->evidence->serviceEvidence($project, $service);

        $question = sprintf(
            'Analyze service "%s" on host %s: current state, what the recent metrics indicate, and recommendations — using only the evidence.',
            $service->service_name,
            $evidence['service']['host'] ?? 'unknown'
        );

        $ai = $this->analyst->narrate(
            $project,
            $user,
            'ops_service_analysis',
            ['service_evidence' => $evidence],
            $question,
            'service_analyze',
            'qynsight.intelligence.services.analyze',
            'text',
            [[
                'source_document_key' => 'qynsight.service.'.($evidence['service']['uuid'] ?? ''),
                'official_reference' => 'Service: '.($evidence['service']['name'] ?? ''),
                'type' => 'service',
            ]],
        );

        return [
            'service' => $evidence['service'],
            'open_alerts' => $evidence['open_alerts'],
            'recent_metrics' => $evidence['recent_metrics'],
            'ai_analysis' => $ai,
        ];
    }

    /**
     * Evidence-based operational recommendations. Every recommendation references real evidence
     * (a metric, an alert, capacity, or a dependency). Never generated without evidence.
     *
     * @return list<array<string, mixed>>
     */
    public function recommendations(Project $project): array
    {
        $recommendations = [];

        // 1) Resource hotspots → scale/remediate, referencing the metric.
        $performance = $this->performance->analyze($project, null);
        foreach ($performance['resource_hotspots'] as $hotspot) {
            $recommendations[] = [
                'type' => 'resource',
                'severity' => $hotspot['severity'],
                'title' => $this->hotspotTitle($hotspot['metric']),
                'target' => $hotspot['host'],
                'rationale' => sprintf('%s utilization on %s is %.1f%% (threshold breached).', strtoupper((string) $hotspot['metric']), $hotspot['host'], (float) $hotspot['value']),
                'evidence' => [['type' => 'metric', 'metric' => $hotspot['metric'], 'value' => $hotspot['value']]],
            ];
        }

        // 2) Capacity runway → expand/scale, referencing the runway.
        $capacity = $this->evidence->capacity($project, '30d');
        foreach ($this->capacityRisks($capacity) as $risk) {
            if ($risk['status'] === 'healthy') {
                continue;
            }
            $recommendations[] = [
                'type' => 'capacity',
                'severity' => $risk['status'] === 'critical' ? 'critical' : 'warning',
                'title' => sprintf('Plan %s capacity expansion', $risk['resource']),
                'target' => 'workspace',
                'rationale' => $risk['days'] !== null
                    ? sprintf('%s runway is ~%d days (status: %s).', ucfirst((string) $risk['resource']), (int) $risk['days'], $risk['status'])
                    : sprintf('%s capacity status is %s.', ucfirst((string) $risk['resource']), $risk['status']),
                'evidence' => [['type' => 'capacity', 'resource' => $risk['resource'], 'runway_days' => $risk['days']]],
            ];
        }

        // 3) Critical alerts → investigate, referencing the alert.
        foreach (array_slice($this->evidence->openAlerts($project, 24, 30), 0, 10) as $alert) {
            if (($alert['severity'] ?? '') !== 'critical') {
                continue;
            }
            $recommendations[] = [
                'type' => 'alert',
                'severity' => 'critical',
                'title' => 'Investigate critical alert',
                'target' => $alert['host'] ?? 'unknown',
                'rationale' => sprintf('Critical alert "%s" is open%s.', $alert['title'] ?? '', ($alert['occurrence_count'] ?? 0) > 1 ? sprintf(' (recurred %d times)', $alert['occurrence_count']) : ''),
                'evidence' => [['type' => 'alert', 'uuid' => $alert['uuid'] ?? null, 'title' => $alert['title'] ?? null]],
            ];
        }

        return $recommendations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function criticalServices(Project $project): array
    {
        $prefix = $this->evidence->hostPrefix($project);

        return ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $prefix.'%')
            ->whereIn('state', ['critical', 'unreachable'])
            ->orderBy('host_name')
            ->limit(25)
            ->get(['id', 'service_name', 'host_name', 'state', 'last_state_change_at'])
            ->map(fn (ObserveService $s): array => [
                'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_SERVICE, $project->id, (int) $s->id),
                'name' => (string) $s->service_name,
                'host' => $this->evidence->unprefixHost($project, (string) $s->host_name),
                'state' => (string) $s->state,
                'since' => optional($s->last_state_change_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $health
     * @param  array<string, mixed>  $performance
     * @param  list<array<string, mixed>>  $openAlerts
     * @return list<array<string, mixed>>
     */
    private function topRisks(array $health, array $performance, array $openAlerts): array
    {
        $risks = [];

        foreach (array_slice($performance['resource_hotspots'], 0, 5) as $hotspot) {
            $risks[] = [
                'kind' => 'resource_hotspot',
                'severity' => $hotspot['severity'],
                'summary' => sprintf('%s on %s at %.1f%%', strtoupper((string) $hotspot['metric']), $hotspot['host'], (float) $hotspot['value']),
            ];
        }

        $criticalAlerts = array_values(array_filter($openAlerts, fn ($a): bool => ($a['severity'] ?? '') === 'critical'));
        if ($criticalAlerts !== []) {
            $risks[] = [
                'kind' => 'critical_alerts',
                'severity' => 'critical',
                'summary' => sprintf('%d critical alert(s) currently open', count($criticalAlerts)),
            ];
        }

        if (($health['unhealthy_host_count'] ?? 0) > 0) {
            $risks[] = [
                'kind' => 'unhealthy_hosts',
                'severity' => 'warning',
                'summary' => sprintf('%d host(s) reporting a degraded state', $health['unhealthy_host_count']),
            ];
        }

        return array_slice($risks, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $capacity
     * @return list<array<string, mixed>>
     */
    private function capacityRisks(array $capacity): array
    {
        $runway = $capacity['runway'] ?? [];
        $risks = [];
        foreach (['cpu', 'memory', 'storage'] as $resource) {
            $entry = $runway[$resource] ?? null;
            if (! is_array($entry)) {
                continue;
            }
            $risks[] = [
                'resource' => $resource,
                'days' => $entry['days'] ?? null,
                'months' => $entry['months'] ?? null,
                'status' => (string) ($entry['status'] ?? 'healthy'),
            ];
        }

        return $risks;
    }

    /**
     * @param  array<string, mixed>  $capacity
     * @return array<string, mixed>
     */
    private function capacitySummary(array $capacity): array
    {
        return [
            'health' => $capacity['health'] ?? null,
            'runway' => $capacity['runway'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentInvestigations(Project $project): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        return AuditLog::query()
            ->where('project_id', $project->id)
            ->where('action', 'like', 'ops_intelligence_%')
            ->orderByDesc('timestamp')
            ->limit(10)
            ->get(['action', 'metadata', 'timestamp'])
            ->map(fn (AuditLog $log): array => [
                'action' => str_replace('ops_intelligence_', '', (string) $log->action),
                'context_type' => is_array($log->metadata) ? ($log->metadata['context_type'] ?? null) : null,
                'at' => optional($log->timestamp)->toIso8601String(),
            ])
            ->all();
    }

    private function hotspotTitle(string $metric): string
    {
        return match ($metric) {
            'cpu' => 'Reduce CPU pressure or add vCPU',
            'memory' => 'Increase RAM or reduce memory usage',
            'disk' => 'Reclaim or expand storage',
            'network' => 'Relieve network saturation',
            default => 'Address resource pressure',
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function overviewCitations(array $evidence): array
    {
        $refs = [['source_document_key' => 'qynsight.infrastructure_health', 'official_reference' => 'Infrastructure health rollup', 'type' => 'health']];
        foreach (($evidence['open_alerts'] ?? []) as $alert) {
            $refs[] = ['source_document_key' => 'qynsight.alert.'.($alert['uuid'] ?? ''), 'official_reference' => 'Alert: '.($alert['title'] ?? ''), 'type' => 'alert'];
        }

        return array_slice($refs, 0, 30);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function hostCitations(array $evidence): array
    {
        return [[
            'source_document_key' => 'qynsight.host.'.($evidence['host']['uuid'] ?? ''),
            'official_reference' => 'Host: '.($evidence['host']['name'] ?? ''),
            'type' => 'host',
        ]];
    }
}

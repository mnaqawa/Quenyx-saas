<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Contracts\QuenyxAI\AiModuleAdapter;
use App\Models\Project;
use App\Services\Observe\Intelligence\OperationsEvidenceCollector;

/**
 * QynSight's adapter into the Quenyx AI Platform (Sprint 21 — Operations Intelligence).
 *
 * QynSight is a LIVE AI consumer: this adapter exposes its operational intelligence capabilities and
 * contextual actions, and builds a deterministic, workspace-scoped evidence context from REAL
 * monitoring data via {@see OperationsEvidenceCollector}. It is a thin seam — it reuses the Sprint 21
 * Operations Intelligence services and the shared Quenyx AI runtime (provider registry, prompt
 * orchestration, conversation surface, audit through {@see \App\Services\Observe\Intelligence\OperationsAiAnalyst}).
 * It moves NO business logic, duplicates NO AI logic, calls NO provider directly, and fabricates NO
 * operational data — when evidence is insufficient the context says so.
 *
 * All action endpoints are workspace-scoped and UUID-only (see {@see \App\Support\Observe\OperationsEntityId}).
 */
class QynSightAiAdapter implements AiModuleAdapter
{
    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
    ) {}

    public function moduleKey(): string
    {
        return 'qynsight';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'monitoring_copilot',
            'alert_intelligence',
            'root_cause_analysis',
            'incident_timeline',
            'capacity_intelligence',
            'performance_intelligence',
            'infrastructure_intelligence',
            'service_health_intelligence',
            'operational_recommendations',
        ];
    }

    /**
     * Deterministic, workspace-scoped operational context (real data only). The shared AI runtime
     * narrates this; nothing here is fabricated.
     *
     * Recognised options: `hours` (int, default 24) for the alert/change window, `capacity_range`
     * (string, default "30d") for the capacity analytics range.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array
    {
        $hours = max(1, (int) ($options['hours'] ?? 24));
        $range = (string) ($options['capacity_range'] ?? '30d');

        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'window_hours' => $hours,
            'infrastructure_health' => $this->evidence->infrastructureHealth($project),
            'open_alerts' => $this->evidence->openAlerts($project, $hours),
            'capacity' => $this->evidence->capacity($project, $range),
            'recent_changes' => $this->evidence->changes($project, $hours),
            'guardrails' => [
                'Use only the operational evidence provided in this context.',
                'Cite the specific hosts, services, alerts, or metrics you rely on.',
                'If the evidence is insufficient to answer, say so explicitly — never fabricate monitoring data.',
            ],
        ];
    }

    /**
     * Contextual "✨ Quenyx AI" actions, mapped to the UUID-only Operations Intelligence endpoints.
     *
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynsight/intelligence';

        return [
            [
                'key' => 'copilot',
                'capability' => 'monitoring_copilot',
                'target' => 'workspace',
                'label' => 'Ask Quenyx AI',
                'method' => 'POST',
                'endpoint' => "{$base}/copilot",
            ],
            [
                'key' => 'overview',
                'capability' => 'operational_recommendations',
                'target' => 'workspace',
                'label' => 'Operations Intelligence',
                'method' => 'GET',
                'endpoint' => "{$base}/overview",
            ],
            [
                'key' => 'explain_alert',
                'capability' => 'alert_intelligence',
                'target' => 'alert',
                'label' => 'Explain',
                'method' => 'POST',
                'endpoint' => "{$base}/alerts/{uuid}/explain",
            ],
            [
                'key' => 'investigate_alert',
                'capability' => 'alert_intelligence',
                'target' => 'alert',
                'label' => 'Investigate',
                'method' => 'POST',
                'endpoint' => "{$base}/alerts/{uuid}/investigate",
            ],
            [
                'key' => 'incident_timeline',
                'capability' => 'incident_timeline',
                'target' => 'incident',
                'label' => 'Timeline',
                'method' => 'GET',
                'endpoint' => "{$base}/incidents/{uuid}/timeline",
            ],
            [
                'key' => 'explain_host',
                'capability' => 'service_health_intelligence',
                'target' => 'host',
                'label' => 'Explain',
                'method' => 'POST',
                'endpoint' => "{$base}/hosts/{uuid}/explain",
            ],
            [
                'key' => 'analyze_service',
                'capability' => 'service_health_intelligence',
                'target' => 'service',
                'label' => 'Analyze',
                'method' => 'POST',
                'endpoint' => "{$base}/services/{uuid}/analyze",
            ],
            [
                'key' => 'predict_capacity',
                'capability' => 'capacity_intelligence',
                'target' => 'capacity',
                'label' => 'Predict',
                'method' => 'POST',
                'endpoint' => "{$base}/capacity/{uuid}/predict",
            ],
            [
                'key' => 'infrastructure_impact',
                'capability' => 'infrastructure_intelligence',
                'target' => 'infrastructure',
                'label' => 'Impact Analysis',
                'method' => 'POST',
                'endpoint' => "{$base}/infrastructure/{uuid}/impact",
            ],
        ];
    }
}

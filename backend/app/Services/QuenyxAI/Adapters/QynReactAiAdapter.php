<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Incident\Incident;
use App\Models\Project;

/**
 * QynReact's adapter into the Quenyx AI Platform (Sprint 23 — Incident Intelligence).
 *
 * Exposes incident intelligence and cross-module orchestration. Its context is intentionally LIGHT
 * (incident counts/severity mix) so the cross-module orchestrator can include it without recursion;
 * the rich per-incident workspace is assembled on demand by the QynReact intelligence service, which
 * itself reuses the other modules' adapters. Reuses the shared Quenyx AI runtime — no duplicated AI
 * logic, no fabricated data.
 */
class QynReactAiAdapter extends AbstractAiModuleAdapter
{
    public function moduleKey(): string
    {
        return 'qynreact';
    }

    public function moduleName(): string
    {
        return 'QynReact';
    }

    public function moduleDescription(): string
    {
        return 'Incident Intelligence — a unified incident workspace that reuses Operations & Asset '
            .'Intelligence and Automation to drive evidence-based response, recommendations, and postmortems.';
    }

    public function moduleCategory(): string
    {
        return 'Incident Response';
    }

    public function moduleIcon(): string
    {
        return 'alert-triangle';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'incident_intelligence',
            'incident_copilot',
            'incident_recommendations',
            'cross_module_orchestration',
            'postmortem_intelligence',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace', 'incident'];
    }

    /**
     * Light, recursion-safe context (no cross-module gather here).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array
    {
        $base = Incident::where('project_id', $project->id);

        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'incident_summary' => [
                'open' => (clone $base)->whereNotIn('status', ['resolved', 'closed'])->count(),
                'total' => (clone $base)->count(),
                'by_severity' => (clone $base)->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity')->all(),
            ],
            'guardrails' => [
                'Use only the incident evidence assembled in the incident workspace.',
                'Never invent alerts, assets, metrics, or outcomes.',
                'Recommend safe, reversible response actions; flag destructive ones as requiring approval.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynreact/incidents';

        return [
            ['key' => 'list', 'capability' => 'incident_intelligence', 'target' => 'workspace', 'label' => 'Incidents', 'method' => 'GET', 'endpoint' => $base],
            ['key' => 'workspace', 'capability' => 'incident_intelligence', 'target' => 'incident', 'label' => 'Open', 'method' => 'GET', 'endpoint' => "{$base}/{uuid}"],
            ['key' => 'copilot', 'capability' => 'incident_copilot', 'target' => 'incident', 'label' => 'Ask Quenyx AI', 'method' => 'POST', 'endpoint' => "{$base}/{uuid}/copilot"],
            ['key' => 'recommend', 'capability' => 'incident_recommendations', 'target' => 'incident', 'label' => 'Recommend', 'method' => 'POST', 'endpoint' => "{$base}/{uuid}/recommend"],
            ['key' => 'postmortem', 'capability' => 'postmortem_intelligence', 'target' => 'incident', 'label' => 'Postmortem', 'method' => 'POST', 'endpoint' => "{$base}/{uuid}/postmortem"],
        ];
    }
}

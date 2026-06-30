<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Project;
use App\Services\Platform\Cost\CostIntelligenceService;

/**
 * QynBalance's adapter into the Quenyx AI Platform (Sprint 25 — Enterprise Cost Intelligence).
 *
 * Builds a deterministic, workspace-scoped context from REAL resource counts (hosts, services, agents,
 * seats, automation activity). It NEVER fabricates financial values — monetary figures appear only when
 * the operator configured real unit rates; otherwise counts are reported with "pricing unavailable".
 * Reuses the shared Quenyx AI runtime — no duplicated AI logic.
 */
class QynBalanceAiAdapter extends AbstractAiModuleAdapter
{
    public function __construct(
        private readonly CostIntelligenceService $cost,
    ) {}

    public function moduleKey(): string
    {
        return 'qynbalance';
    }

    public function moduleName(): string
    {
        return 'QynBalance';
    }

    public function moduleDescription(): string
    {
        return 'Enterprise Cost Intelligence — infrastructure cost analysis, capacity/license/cloud optimization, '
            .'automation savings, asset utilization, and budget forecasting from real platform data (no fabricated financials).';
    }

    public function moduleCategory(): string
    {
        return 'Enterprise Intelligence';
    }

    public function moduleIcon(): string
    {
        return 'scale';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'cost_intelligence',
            'capacity_optimization',
            'license_optimization',
            'asset_utilization',
            'budget_forecast',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array
    {
        $overview = $this->cost->overview($project);

        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'currency' => $overview['currency'],
            'pricing_configured' => $overview['pricing_configured'],
            'estimated_monthly' => $overview['infrastructure']['estimated_monthly_total'] ?? null,
            'recommendation_count' => count($overview['recommendations']),
            'guardrails' => [
                'Never fabricate financial values; if pricing is unavailable, say so and report counts only.',
                'Optimization is evidence-based and advisory — never auto-applied.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynbalance';

        return [
            ['key' => 'overview', 'capability' => 'cost_intelligence', 'target' => 'workspace', 'label' => 'Cost Overview', 'method' => 'GET', 'endpoint' => "{$base}/cost/overview"],
            ['key' => 'copilot', 'capability' => 'cost_intelligence', 'target' => 'workspace', 'label' => 'Ask Cost Intelligence', 'method' => 'POST', 'endpoint' => "{$base}/cost/copilot"],
        ];
    }
}

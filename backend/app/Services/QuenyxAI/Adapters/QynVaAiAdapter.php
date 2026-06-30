<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Project;
use App\Services\QuenyxAI\AiModuleAdapterRegistry;

/**
 * QynVA's adapter into the Quenyx AI Platform (Sprint 25 — Enterprise AI Operator).
 *
 * QynVA is the cross-module operator. Its context is a DETERMINISTIC summary of the platform's discovered
 * modules and capabilities (read from the AI Adapter Registry) — it deliberately does NOT gather other
 * modules' contexts here (that is the Enterprise Context Engine's job, invoked by the operator service),
 * which also keeps registry introspection free of recursion. Reuses the shared AI runtime — no duplicated
 * AI/orchestration logic, nothing fabricated.
 */
class QynVaAiAdapter extends AbstractAiModuleAdapter
{
    public function __construct(
        private readonly AiModuleAdapterRegistry $registry,
    ) {}

    public function moduleKey(): string
    {
        return 'qynva';
    }

    public function moduleName(): string
    {
        return 'QynVA';
    }

    public function moduleDescription(): string
    {
        return 'Enterprise AI Operator — discovers modules and capabilities, builds a single enterprise context, '
            .'reasons, recommends, and coordinates cross-module plans through existing platform services (never executes directly).';
    }

    public function moduleCategory(): string
    {
        return 'Enterprise Intelligence';
    }

    public function moduleIcon(): string
    {
        return 'cpu';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'enterprise_operator',
            'cross_module_coordination',
            'context_engine',
            'executive_intelligence',
            'platform_analytics',
            'platform_health',
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
        $modules = [];
        foreach ($this->registry->all() as $adapter) {
            if ($adapter->moduleKey() === $this->moduleKey()) {
                continue;
            }
            $modules[] = ['module' => $adapter->moduleKey(), 'capabilities' => $adapter->capabilities()];
        }

        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'coordinated_modules' => $modules,
            'guardrails' => [
                'Reason and recommend only from the provided enterprise context.',
                'Reference existing module actions by key; never execute and never invent results.',
                'All plans are editable and require human approval before any module acts.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynva';

        return [
            ['key' => 'operate', 'capability' => 'enterprise_operator', 'target' => 'workspace', 'label' => 'Ask the Operator', 'method' => 'POST', 'endpoint' => "{$base}/operator/operate"],
            ['key' => 'capabilities', 'capability' => 'cross_module_coordination', 'target' => 'workspace', 'label' => 'Discover Capabilities', 'method' => 'GET', 'endpoint' => "{$base}/operator/capabilities"],
            ['key' => 'executive_summary', 'capability' => 'executive_intelligence', 'target' => 'workspace', 'label' => 'Executive Summary', 'method' => 'POST', 'endpoint' => "{$base}/executive/summary"],
            ['key' => 'analytics', 'capability' => 'platform_analytics', 'target' => 'workspace', 'label' => 'Enterprise Analytics', 'method' => 'GET', 'endpoint' => "{$base}/analytics"],
            ['key' => 'health', 'capability' => 'platform_health', 'target' => 'workspace', 'label' => 'Platform Health', 'method' => 'GET', 'endpoint' => "{$base}/health"],
        ];
    }
}

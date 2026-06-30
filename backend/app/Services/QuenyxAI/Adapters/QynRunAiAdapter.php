<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Project;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\AutomationAdapterRegistry;
use App\Services\Automation\AutomationLearningService;

/**
 * QynRun's adapter into the Quenyx AI Platform (Sprint 23 — Enterprise Automation).
 *
 * Exposes automation intelligence (runbook drafting, workflow/execution explanation, evidence-based
 * automation recommendations) and builds a deterministic, workspace-scoped context from REAL evidence:
 * the registry-discovered execution adapters + action catalog and the auditable Automation Learning
 * statistics. It reuses the shared Quenyx AI runtime ({@see \App\Services\AI\ModuleAiNarrator}) — no
 * AI logic, provider logic, or orchestration is duplicated, and nothing is fabricated.
 */
class QynRunAiAdapter extends AbstractAiModuleAdapter
{
    public function __construct(
        private readonly AutomationAdapterRegistry $adapters,
        private readonly ActionRegistry $actions,
        private readonly AutomationLearningService $learning,
    ) {}

    public function moduleKey(): string
    {
        return 'qynrun';
    }

    public function moduleName(): string
    {
        return 'QynRun';
    }

    public function moduleDescription(): string
    {
        return 'Enterprise Automation — registry-driven execution, workflows, runbooks, approvals, '
            .'rollback, and AI-assisted runbook drafting grounded in auditable automation history.';
    }

    public function moduleCategory(): string
    {
        return 'Automation';
    }

    public function moduleIcon(): string
    {
        return 'play-circle';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'runbook_intelligence',
            'workflow_intelligence',
            'execution_intelligence',
            'automation_recommendations',
            'automation_copilot',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace', 'workflow', 'runbook', 'execution'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array
    {
        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'adapters' => $this->adapters->describeAll(),
            'action_catalog' => $this->actions->all(),
            'learning' => $this->learning->stats($project),
            'live_execution_enabled' => (bool) config('automation.live_execution', false),
            'guardrails' => [
                'Use only the registered adapters and action catalog provided in this context.',
                'Prefer safe, reversible, diagnostic-first automation.',
                'Flag every destructive step as requiring approval — never auto-execute.',
                'Cite the historical learning statistics when recommending an action.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynrun/intelligence';

        return [
            ['key' => 'copilot', 'capability' => 'automation_copilot', 'target' => 'workspace', 'label' => 'Ask Quenyx AI', 'method' => 'POST', 'endpoint' => "{$base}/copilot"],
            ['key' => 'overview', 'capability' => 'automation_recommendations', 'target' => 'workspace', 'label' => 'Automation Intelligence', 'method' => 'GET', 'endpoint' => "{$base}/overview"],
            ['key' => 'suggest_runbook', 'capability' => 'runbook_intelligence', 'target' => 'workspace', 'label' => 'Draft Runbook', 'method' => 'POST', 'endpoint' => "{$base}/runbooks/suggest"],
            ['key' => 'explain_execution', 'capability' => 'execution_intelligence', 'target' => 'execution', 'label' => 'Explain', 'method' => 'POST', 'endpoint' => "{$base}/executions/{uuid}/explain"],
        ];
    }
}

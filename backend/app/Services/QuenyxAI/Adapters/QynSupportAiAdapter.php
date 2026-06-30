<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Project;
use App\Models\Support\Ticket;

/**
 * QynSupport's adapter into the Quenyx AI Platform (Sprint 24 — Service Desk / Ticket Intelligence).
 *
 * Exposes evidence-based ticket triage (category/priority/impact/assignee/SLA + related incidents,
 * assets, runbooks) and a ticket copilot. Builds a deterministic, workspace-scoped context from REAL
 * ticket data. Reuses the shared Quenyx AI runtime — no duplicated AI logic, nothing fabricated.
 */
class QynSupportAiAdapter extends AbstractAiModuleAdapter
{
    public function moduleKey(): string
    {
        return 'qynsupport';
    }

    public function moduleName(): string
    {
        return 'QynSupport';
    }

    public function moduleDescription(): string
    {
        return 'Enterprise Service Desk — tickets with evidence-based AI triage (category, priority, impact, '
            .'assignee, SLA) and related incidents, assets, and runbooks. Suggestions are editable, never auto-applied.';
    }

    public function moduleCategory(): string
    {
        return 'Service Desk';
    }

    public function moduleIcon(): string
    {
        return 'life-buoy';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'ticket_intelligence',
            'ticket_triage',
            'ticket_copilot',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace', 'ticket'];
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
            'counts' => [
                'tickets' => Ticket::where('project_id', $project->id)->count(),
                'open' => Ticket::where('project_id', $project->id)->where('status', 'open')->count(),
            ],
            'by_priority' => Ticket::where('project_id', $project->id)
                ->selectRaw('priority, count(*) as c')->groupBy('priority')->pluck('c', 'priority')->all(),
            'guardrails' => [
                'Use only real ticket data and workspace history provided in this context.',
                'Suggestions are evidence-based and editable; never auto-apply triage or assignment.',
                'Report insufficient evidence honestly rather than inventing assignees or relations.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynsupport';

        return [
            ['key' => 'analyze', 'capability' => 'ticket_triage', 'target' => 'ticket', 'label' => 'AI Triage', 'method' => 'POST', 'endpoint' => "{$base}/tickets/{uuid}/intelligence/analyze"],
            ['key' => 'copilot', 'capability' => 'ticket_copilot', 'target' => 'ticket', 'label' => 'Ask Quenyx AI', 'method' => 'POST', 'endpoint' => "{$base}/tickets/{uuid}/intelligence/copilot"],
        ];
    }
}

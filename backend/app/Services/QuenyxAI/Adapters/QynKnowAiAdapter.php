<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Project;
use App\Services\Knowledge\KnowledgeSourceRegistry;

/**
 * QynKnow's adapter into the Quenyx AI Platform (Sprint 24 — Enterprise Knowledge Platform).
 *
 * Exposes knowledge intelligence (explain, summarize, find related, editable drafting) and builds a
 * deterministic, workspace-scoped context from REAL evidence: the registered Knowledge Sources and the
 * indexed document corpus. Reuses the shared Quenyx AI runtime ({@see \App\Services\AI\ModuleAiNarrator})
 * — no AI/provider/orchestration logic is duplicated and nothing is fabricated.
 */
class QynKnowAiAdapter extends AbstractAiModuleAdapter
{
    public function __construct(
        private readonly KnowledgeSourceRegistry $sources,
    ) {}

    public function moduleKey(): string
    {
        return 'qynknow';
    }

    public function moduleName(): string
    {
        return 'QynKnow';
    }

    public function moduleDescription(): string
    {
        return 'Enterprise Knowledge — registry-driven knowledge sources, enterprise & semantic search, '
            .'knowledge graph, and an AI assistant that explains, summarizes, relates, and drafts from real evidence.';
    }

    public function moduleCategory(): string
    {
        return 'Knowledge';
    }

    public function moduleIcon(): string
    {
        return 'book-open';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'knowledge_assistant',
            'enterprise_search',
            'semantic_search',
            'knowledge_graph',
            'kb_drafting',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace', 'document', 'source'];
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
            'sources' => $this->sources->describe($project),
            'document_count' => KnowledgeDocument::where('project_id', $project->id)->count(),
            'guardrails' => [
                'Use only indexed knowledge documents and registered sources provided in this context.',
                'Never fabricate citations, sources, or facts.',
                'All AI drafts are editable and are never auto-published.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynknow/intelligence';

        return [
            ['key' => 'copilot', 'capability' => 'knowledge_assistant', 'target' => 'workspace', 'label' => 'Ask Quenyx AI', 'method' => 'POST', 'endpoint' => "{$base}/copilot"],
            ['key' => 'explain', 'capability' => 'knowledge_assistant', 'target' => 'document', 'label' => 'Explain', 'method' => 'POST', 'endpoint' => "{$base}/documents/{uuid}/explain"],
            ['key' => 'summarize', 'capability' => 'knowledge_assistant', 'target' => 'document', 'label' => 'Summarize', 'method' => 'POST', 'endpoint' => "{$base}/documents/{uuid}/summarize"],
            ['key' => 'find_related', 'capability' => 'enterprise_search', 'target' => 'workspace', 'label' => 'Find Related', 'method' => 'POST', 'endpoint' => "{$base}/related"],
            ['key' => 'draft', 'capability' => 'kb_drafting', 'target' => 'workspace', 'label' => 'Draft (KB/Summary/Runbook)', 'method' => 'POST', 'endpoint' => "{$base}/draft"],
        ];
    }
}

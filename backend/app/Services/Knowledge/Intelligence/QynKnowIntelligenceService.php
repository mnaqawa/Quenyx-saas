<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\Ai\ModuleAiNarrator;
use App\Services\Incident\CrossModuleOrchestrator;
use App\Services\Knowledge\EnterpriseSearchService;
use App\Services\Knowledge\KnowledgeSourceRegistry;

/**
 * Sprint 24 — Knowledge Assistant (QynKnow).
 *
 * Reuses the shared Quenyx AI runtime ({@see ModuleAiNarrator}) to Explain, Summarize, Find related,
 * and generate EDITABLE drafts (KB article, incident/executive/technical summary, runbook). Every answer
 * is grounded in real evidence — indexed documents (via the Knowledge Source Registry / Enterprise
 * Search) and cross-module context (via the AI Adapter Registry, no module branching). Drafts are never
 * auto-published or auto-executed, and nothing is fabricated.
 */
class QynKnowIntelligenceService
{
    private const AUDIT_PREFIX = 'knowledge_intelligence_';

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an enterprise knowledge architect for QynKnow. '
        .'Explain, summarize, relate, and draft documentation using ONLY the provided evidence: indexed knowledge '
        .'documents, search results, and cross-module context. Never invent facts, sources, or citations. Produce '
        .'clear, editable drafts and clearly mark assumptions. Cite the evidence you use.';

    /** @var list<string> Supported editable draft kinds. */
    public const DRAFT_KINDS = ['kb', 'incident_summary', 'executive_summary', 'technical_summary', 'runbook'];

    public function __construct(
        private readonly EnterpriseSearchService $search,
        private readonly KnowledgeSourceRegistry $sources,
        private readonly CrossModuleOrchestrator $crossModule,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        return [
            'sources' => $this->sources->describe($project),
            'document_count' => KnowledgeDocument::where('project_id', $project->id)->count(),
            'by_status' => KnowledgeDocument::where('project_id', $project->id)
                ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        $evidence = [
            'search' => $this->search->search($project, $question, ['limit' => 8]),
            'sources' => $this->sources->describe($project),
        ];
        $ai = $this->narrate($project, $user, 'knowledge_copilot', $evidence, $question, 'copilot', 'qynknow.intelligence.copilot');

        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Knowledge Assistant',
                'origin' => 'qynknow_knowledge_intelligence',
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
     * @return array<string, mixed>
     */
    public function explain(Project $project, ?User $user, KnowledgeDocument $doc): array
    {
        $evidence = ['document' => $this->docEvidence($doc)];
        $question = sprintf('Explain the document "%s" in plain language: what it covers, when to use it, and key steps. Use only the document.', $doc->title);
        $ai = $this->narrate($project, $user, 'knowledge_explain', $evidence, $question, 'explain', 'qynknow.intelligence.explain');

        return ['document' => $evidence['document'], 'explanation' => $ai];
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(Project $project, ?User $user, KnowledgeDocument $doc): array
    {
        $evidence = ['document' => $this->docEvidence($doc)];
        $question = sprintf('Summarize the document "%s" into a concise abstract and 3-5 key points. Use only the document.', $doc->title);
        $ai = $this->narrate($project, $user, 'knowledge_summarize', $evidence, $question, 'summarize', 'qynknow.intelligence.summarize');

        return ['document' => $evidence['document'], 'summary' => $ai];
    }

    /**
     * @return array<string, mixed>
     */
    public function findRelated(Project $project, ?User $user, string $query): array
    {
        $results = $this->search->search($project, $query, ['limit' => 10]);
        $evidence = ['query' => $query, 'search' => $results];
        $question = sprintf('Given the topic "%s", explain how the related items connect and which to consult first. Use only the search results.', $query);
        $ai = $this->narrate($project, $user, 'knowledge_find_related', $evidence, $question, 'find_related', 'qynknow.intelligence.related');

        return ['query' => $query, 'results' => $results['results'], 'ai_explanation' => $ai];
    }

    /**
     * Generate an EDITABLE draft. Returns AI content + a saveable document scaffold (never auto-saved).
     *
     * @return array<string, mixed>
     */
    public function draft(Project $project, ?User $user, string $kind, string $topic): array
    {
        $kind = in_array($kind, self::DRAFT_KINDS, true) ? $kind : 'kb';

        $evidence = [
            'topic' => $topic,
            'search' => $this->search->search($project, $topic, ['limit' => 8]),
            'cross_module' => $this->crossModule->gather($project, ['qynknow']),
        ];

        $question = $this->draftQuestion($kind, $topic);
        $ai = $this->narrate($project, $user, 'knowledge_draft_'.$kind, $evidence, $question, 'draft_'.$kind, 'qynknow.intelligence.draft');

        return [
            'kind' => $kind,
            'topic' => $topic,
            'ai_draft' => $ai,
            'document_scaffold' => [
                'title' => $this->draftTitle($kind, $topic),
                'category' => $this->draftCategory($kind),
                'status' => 'draft',
                'format' => 'markdown',
                'body' => $ai['content'] ?? '',
            ],
            'note' => 'Editable AI-assisted draft. Review and save manually — AI drafts are never auto-published.',
        ];
    }

    private function draftQuestion(string $kind, string $topic): string
    {
        return match ($kind) {
            'incident_summary' => sprintf('Draft an incident summary for "%s": impact, timeline, contributing factors (hypotheses), and follow-ups. Use only the evidence.', $topic),
            'executive_summary' => sprintf('Draft a one-page executive summary for "%s" suitable for leadership. Use only the evidence.', $topic),
            'technical_summary' => sprintf('Draft a technical summary for "%s" for engineers: architecture, failure modes, and remediation. Use only the evidence.', $topic),
            'runbook' => sprintf('Draft an editable runbook for "%s": diagnostic-first steps, then remediation, flagging destructive steps as requiring approval. Use only the evidence.', $topic),
            default => sprintf('Draft a knowledge base article for "%s": overview, prerequisites, steps, and references. Use only the evidence.', $topic),
        };
    }

    private function draftTitle(string $kind, string $topic): string
    {
        return match ($kind) {
            'incident_summary' => 'Incident Summary: '.$topic,
            'executive_summary' => 'Executive Summary: '.$topic,
            'technical_summary' => 'Technical Summary: '.$topic,
            'runbook' => 'Runbook: '.$topic,
            default => $topic,
        };
    }

    private function draftCategory(string $kind): string
    {
        return match ($kind) {
            'runbook' => 'runbook',
            'incident_summary' => 'incident',
            'executive_summary', 'technical_summary' => 'summary',
            default => 'article',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function docEvidence(KnowledgeDocument $doc): array
    {
        return [
            'uuid' => $doc->uuid,
            'title' => $doc->title,
            'category' => $doc->category,
            'format' => $doc->format,
            'body' => $doc->body,
            'tags' => (array) ($doc->tags ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function narrate(Project $project, ?User $user, string $contextType, array $evidence, string $question, string $action, string $endpoint): array
    {
        return $this->narrator->narrate(
            $project,
            $user,
            $contextType,
            $evidence,
            $question,
            self::ROLE_PREAMBLE,
            self::AUDIT_PREFIX.$action,
            $endpoint,
            ModuleAiNarrator::DEFAULT_GUARDRAILS,
            'text',
            [['source_document_key' => 'qynknow.knowledge_evidence', 'official_reference' => 'Knowledge evidence', 'type' => 'knowledge']],
        );
    }
}

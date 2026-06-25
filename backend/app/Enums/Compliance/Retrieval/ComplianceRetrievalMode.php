<?php

namespace App\Enums\Compliance\Retrieval;

/**
 * Deterministic retrieval modes (QCIF Sprint 15). Each mode maps to a fixed set of existing AI
 * Skills whose deterministic output is turned into retrieval candidates. NO vector search, NO
 * embeddings, NO AI ranking — the mode only decides WHICH corpus/graph/tenant context is gathered.
 */
enum ComplianceRetrievalMode: string
{
    case CorpusOnly = 'corpus_only';
    case GraphExpanded = 'graph_expanded';
    case EvidenceAware = 'evidence_aware';
    case GapAware = 'gap_aware';
    case RecommendationAware = 'recommendation_aware';
    case CopilotContext = 'copilot_context';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    public static function fromName(?string $value, self $default = self::CorpusOnly): self
    {
        return self::tryFrom((string) $value) ?? $default;
    }

    /**
     * The corpus/graph/mapping skills that need framework+release scope.
     *
     * @return list<string>
     */
    public function corpusSkills(): array
    {
        return match ($this) {
            self::CorpusOnly => ['corpus_search'],
            self::GraphExpanded => ['corpus_search', 'knowledge_graph'],
            self::EvidenceAware, self::GapAware => ['corpus_search'],
            self::RecommendationAware => ['corpus_search'],
            self::CopilotContext => ['corpus_search', 'knowledge_graph', 'framework_mapping'],
        };
    }

    /**
     * The workspace-scoped (tenant) skills the mode adds.
     *
     * @return list<string>
     */
    public function workspaceSkills(): array
    {
        return match ($this) {
            self::EvidenceAware => ['evidence'],
            self::GapAware => ['gap_assessment'],
            self::RecommendationAware => ['gap_assessment', 'recommendation'],
            self::CopilotContext => ['evidence', 'gap_assessment', 'recommendation'],
            default => [],
        };
    }
}

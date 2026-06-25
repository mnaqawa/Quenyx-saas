<?php

namespace App\Enums\Compliance\Reasoning;

use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;

/**
 * The closed set of deterministic decisions the Compliance Reasoning Engine can make
 * (QCIF Sprint 16). The decision is resolved by rules from the intent + context — NEVER by an LLM.
 * It tells the downstream Prompt Orchestrator WHAT to answer (the `answerStrategy`), before any
 * model is called.
 */
enum ComplianceReasoningDecisionType: string
{
    case ControlExplanation = 'control_explanation';
    case GapAnalysis = 'gap_analysis';
    case EvidenceReview = 'evidence_review';
    case Recommendation = 'recommendation';
    case FrameworkMapping = 'framework_mapping';
    case KnowledgeNavigation = 'knowledge_navigation';
    case SearchSummary = 'search_summary';
    case Unsupported = 'unsupported';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Deterministic base mapping from a Copilot intent. Refinement to framework_mapping /
     * knowledge_navigation is done by the planner using context signals.
     */
    public static function fromIntent(ComplianceCopilotIntent $intent): self
    {
        return match ($intent) {
            ComplianceCopilotIntent::ControlExplanation => self::ControlExplanation,
            ComplianceCopilotIntent::GapSummary => self::GapAnalysis,
            ComplianceCopilotIntent::EvidenceStatus => self::EvidenceReview,
            ComplianceCopilotIntent::RecommendationSummary => self::Recommendation,
            ComplianceCopilotIntent::SearchCorpus => self::SearchSummary,
            ComplianceCopilotIntent::Unsupported => self::Unsupported,
        };
    }

    public function isSupported(): bool
    {
        return $this !== self::Unsupported;
    }

    /**
     * The deterministic instruction handed to the Prompt Orchestrator: HOW the model must answer
     * for this decision. The model never chooses the strategy.
     */
    public function answerStrategy(): string
    {
        return match ($this) {
            self::ControlExplanation => 'explain_control_from_corpus_citations',
            self::GapAnalysis => 'summarize_gap_findings_with_priorities',
            self::EvidenceReview => 'summarize_evidence_status',
            self::Recommendation => 'prioritize_recommendations',
            self::FrameworkMapping => 'explain_cross_framework_mapping',
            self::KnowledgeNavigation => 'navigate_related_controls',
            self::SearchSummary => 'summarize_search_results',
            self::Unsupported => 'decline_unsupported',
        };
    }

    /**
     * Whether the decision MUST be backed by official corpus citations (control/requirement text).
     * Engine-grounded decisions (gap/evidence/recommendation) are grounded by deterministic skill
     * results + the corpus revision instead.
     */
    public function requiresCorpusCitations(): bool
    {
        return match ($this) {
            self::ControlExplanation, self::FrameworkMapping, self::KnowledgeNavigation, self::SearchSummary => true,
            default => false,
        };
    }
}

<?php

namespace App\Enums\Compliance\Copilot;

/**
 * The closed set of intents the Compliance Copilot v0 supports (QCIF Sprint 14). Intent
 * classification is DETERMINISTIC (keyword/regex rules) — there is no open-ended general chat and
 * no LLM-based intent detection. Anything outside this set returns `unsupported_intent`.
 */
enum ComplianceCopilotIntent: string
{
    case ControlExplanation = 'control_explanation';
    case GapSummary = 'gap_summary';
    case EvidenceStatus = 'evidence_status';
    case RecommendationSummary = 'recommendation_summary';
    case SearchCorpus = 'search_corpus';
    case Unsupported = 'unsupported_intent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isSupported(): bool
    {
        return $this !== self::Unsupported;
    }

    /**
     * Whether answers for this intent MUST be backed by corpus source citations (control text).
     * Engine-grounded intents (gap/evidence/recommendation) are instead grounded by deterministic
     * skill results + corpus revision references.
     */
    public function requiresCorpusCitations(): bool
    {
        return match ($this) {
            self::ControlExplanation, self::SearchCorpus => true,
            default => false,
        };
    }
}

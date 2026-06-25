<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

/**
 * A human-readable, deterministic explanation of the reasoning outcome (QCIF Sprint 16): which
 * decision was made, which rules fired, and the resulting counts. This is the audit-friendly
 * summary of the business reasoning — not a chain-of-thought. Pure data.
 */
final readonly class ComplianceReasoningExplanation
{
    /**
     * @param  list<string>  $appliedRuleIds
     */
    public function __construct(
        public string $decisionType,
        public string $answerStrategy,
        public array $appliedRuleIds,
        public int $factCount,
        public int $findingCount,
        public int $recommendationCount,
        public int $missingInformationCount,
        public string $summaryEn,
        public string $summaryAr,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision_type' => $this->decisionType,
            'answer_strategy' => $this->answerStrategy,
            'applied_rule_ids' => $this->appliedRuleIds,
            'fact_count' => $this->factCount,
            'finding_count' => $this->findingCount,
            'recommendation_count' => $this->recommendationCount,
            'missing_information_count' => $this->missingInformationCount,
            'summary_en' => $this->summaryEn,
            'summary_ar' => $this->summaryAr,
        ];
    }
}

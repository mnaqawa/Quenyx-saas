<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

/**
 * A deterministic remediation recommendation produced by a reasoning rule (QCIF Sprint 16), e.g.
 * "collect required evidence", "remediate open gaps". Priority is rule-assigned, never probabilistic
 * and never decided by an LLM. UUID-only. Pure data.
 */
final readonly class ReasoningRecommendation
{
    /**
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public string $uuid,
        public string $ruleId,
        public string $action,
        public string $priority,
        public string $summaryEn,
        public string $summaryAr,
        public ?string $entityCode = null,
        public array $citations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'rule_id' => $this->ruleId,
            'action' => $this->action,
            'priority' => $this->priority,
            'summary_en' => $this->summaryEn,
            'summary_ar' => $this->summaryAr,
            'entity_code' => $this->entityCode,
            'citations' => $this->citations,
        ];
    }
}

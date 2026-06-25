<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

/**
 * A deterministic business finding produced by a reasoning rule (QCIF Sprint 16), e.g. "missing
 * evidence", "open gaps", "compliant". Carries the rule that produced it and its citations. UUID-
 * only. Pure data — no LLM decides this.
 */
final readonly class ReasoningFinding
{
    /**
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public string $uuid,
        public string $ruleId,
        public string $code,
        public string $severity,
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
            'code' => $this->code,
            'severity' => $this->severity,
            'summary_en' => $this->summaryEn,
            'summary_ar' => $this->summaryAr,
            'entity_code' => $this->entityCode,
            'citations' => $this->citations,
        ];
    }
}

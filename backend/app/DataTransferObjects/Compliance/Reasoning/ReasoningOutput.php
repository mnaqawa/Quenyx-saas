<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

/**
 * The structured, deterministic output of the Compliance Reasoning Engine (QCIF Sprint 16).
 *
 * This is the decision layer between Retrieval and the LLM. It contains NO natural-language answer —
 * only structured reasoning: facts, findings, recommendations, missing information, citations,
 * guardrails, warnings, the answer strategy, and the reasoning trace. The Prompt Orchestrator
 * consumes THIS (not raw skills) to build the prompt. Pure data.
 */
final readonly class ReasoningOutput
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<ReasoningFinding>  $findings
     * @param  list<ReasoningRecommendation>  $recommendations
     * @param  list<array<string, mixed>>  $missingInformation
     * @param  list<array<string, mixed>>  $citations
     * @param  array<string, bool>  $guardrails
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ComplianceReasoningDecision $decision,
        public array $facts,
        public array $findings,
        public array $recommendations,
        public array $missingInformation,
        public array $citations,
        public array $guardrails,
        public array $warnings,
        public ReasoningTrace $trace,
        public ComplianceReasoningExplanation $explanation,
        public string $generatedAt,
    ) {}

    public function answerStrategy(): string
    {
        return $this->decision->answerStrategy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->toArray(),
            'answer_strategy' => $this->decision->answerStrategy,
            'facts' => $this->facts,
            'findings' => array_map(fn (ReasoningFinding $f) => $f->toArray(), $this->findings),
            'recommendations' => array_map(fn (ReasoningRecommendation $r) => $r->toArray(), $this->recommendations),
            'missing_information' => $this->missingInformation,
            'citations' => $this->citations,
            'guardrails' => $this->guardrails,
            'warnings' => $this->warnings,
            'reasoning_trace' => $this->trace->toArray(),
            'explanation' => $this->explanation->toArray(),
            'generated_at' => $this->generatedAt,
        ];
    }
}

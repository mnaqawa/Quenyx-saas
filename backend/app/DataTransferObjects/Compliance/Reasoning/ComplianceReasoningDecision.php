<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

use App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType;

/**
 * The deterministic decision produced by the reasoning planner (QCIF Sprint 16): WHAT kind of
 * answer is required and HOW it must be produced (`answerStrategy`) — decided by rules, never by an
 * LLM. Pure data.
 */
final readonly class ComplianceReasoningDecision
{
    /**
     * @param  list<string>  $notes  deterministic notes explaining how the type was resolved
     */
    public function __construct(
        public ComplianceReasoningDecisionType $type,
        public string $answerStrategy,
        public bool $requiresCorpusCitations,
        public array $notes = [],
    ) {}

    public static function for(ComplianceReasoningDecisionType $type, array $notes = []): self
    {
        return new self(
            type: $type,
            answerStrategy: $type->answerStrategy(),
            requiresCorpusCitations: $type->requiresCorpusCitations(),
            notes: array_values($notes),
        );
    }

    public function isSupported(): bool
    {
        return $this->type->isSupported();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'answer_strategy' => $this->answerStrategy,
            'requires_corpus_citations' => $this->requiresCorpusCitations,
            'notes' => $this->notes,
        ];
    }
}

<?php

namespace App\DataTransferObjects\Ai;

/**
 * The fully-assembled prompt produced by the orchestrator from an AI Context payload:
 * a system prompt, a user prompt, the citations to ground answers, and the guardrails that
 * must be honored. This is pure data — it contains no corpus queries and no DB state.
 */
final readonly class AiPrompt
{
    /**
     * @param  list<array<string, mixed>>  $citations
     * @param  array<string, bool>  $guardrails
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public array $citations = [],
        public array $guardrails = [],
        public array $metadata = [],
    ) {}

    /**
     * Convert to provider-agnostic messages. Citations + guardrails are embedded in the
     * system prompt so any provider honors them without provider-specific wiring.
     *
     * @return list<AiMessage>
     */
    public function toMessages(): array
    {
        return [
            AiMessage::system($this->systemPrompt),
            AiMessage::user($this->userPrompt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'system_prompt' => $this->systemPrompt,
            'user_prompt' => $this->userPrompt,
            'citations' => $this->citations,
            'guardrails' => $this->guardrails,
            'metadata' => $this->metadata,
        ];
    }
}

<?php

namespace App\DataTransferObjects\Ai;

/**
 * The data a skill produces: an AI Context payload plus the citations and guardrails needed to
 * ground it, and any warnings. This is pure corpus-derived data — no prompt and no AI output.
 */
final readonly class AiSkillResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $citations
     * @param  array<string, bool>  $guardrails
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $skillKey,
        public ?string $contextType,
        public array $payload,
        public array $citations = [],
        public array $guardrails = [],
        public array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skillKey,
            'context_type' => $this->contextType,
            'payload' => $this->payload,
            'citations' => $this->citations,
            'guardrails' => $this->guardrails,
            'warnings' => $this->warnings,
        ];
    }
}

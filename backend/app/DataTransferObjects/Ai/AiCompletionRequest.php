<?php

namespace App\DataTransferObjects\Ai;

/**
 * Provider-agnostic completion request. The orchestration layer builds this; providers
 * translate it into their own wire format. `model` is nullable — providers resolve it from
 * config when omitted (models are never hardcoded).
 */
final readonly class AiCompletionRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  'text'|'json'  $responseFormat
     * @param  array<string, mixed>|null  $jsonSchema
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $messages,
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public string $responseFormat = 'text',
        public ?array $jsonSchema = null,
        public bool $stream = false,
        public array $metadata = [],
    ) {}

    /**
     * @return list<array{role: string, content: string}>
     */
    public function messagesArray(): array
    {
        return array_map(fn (AiMessage $m) => $m->toArray(), $this->messages);
    }
}

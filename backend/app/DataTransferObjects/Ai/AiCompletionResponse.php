<?php

namespace App\DataTransferObjects\Ai;

/**
 * Provider-agnostic completion response. `structured` holds parsed JSON when the request asked
 * for structured output; `citations` carries any source annotations returned by the provider.
 */
final readonly class AiCompletionResponse
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  list<array<string, mixed>>  $citations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $provider,
        public ?string $model,
        public string $content,
        public ?array $structured,
        public array $citations,
        public AiUsage $usage,
        public ?string $finishReason = null,
        public ?string $id = null,
        public bool $mocked = false,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'content' => $this->content,
            'structured' => $this->structured,
            'citations' => $this->citations,
            'usage' => $this->usage->toArray(),
            'finish_reason' => $this->finishReason,
            'id' => $this->id,
            'mocked' => $this->mocked,
            'metadata' => $this->metadata,
        ];
    }
}

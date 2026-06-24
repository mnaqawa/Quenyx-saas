<?php

namespace App\DataTransferObjects\Ai;

final readonly class AiEmbeddingsResponse
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public string $provider,
        public ?string $model,
        public array $vectors,
        public AiUsage $usage,
        public bool $mocked = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'vectors' => $this->vectors,
            'usage' => $this->usage->toArray(),
            'mocked' => $this->mocked,
        ];
    }
}

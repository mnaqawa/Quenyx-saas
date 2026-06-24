<?php

namespace App\DataTransferObjects\Ai;

final readonly class AiStreamChunk
{
    public function __construct(
        public string $delta,
        public bool $done = false,
        public ?AiUsage $usage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'delta' => $this->delta,
            'done' => $this->done,
            'usage' => $this->usage?->toArray(),
        ];
    }
}

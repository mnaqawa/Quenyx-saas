<?php

namespace App\DataTransferObjects\Ai;

final readonly class AiProviderHealth
{
    public function __construct(
        public string $provider,
        public bool $ok,
        public string $status,
        public ?string $detail = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'ok' => $this->ok,
            'status' => $this->status,
            'detail' => $this->detail,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}

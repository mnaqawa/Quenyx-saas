<?php

namespace App\DataTransferObjects\Ai;

use App\Enums\Ai\AiMessageRole;

final readonly class AiMessage
{
    public function __construct(
        public AiMessageRole $role,
        public string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(AiMessageRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(AiMessageRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(AiMessageRole::Assistant, $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }
}

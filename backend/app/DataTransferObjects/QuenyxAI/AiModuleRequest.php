<?php

namespace App\DataTransferObjects\QuenyxAI;

/**
 * A generic, module-agnostic AI request handed to a module adapter (QCIF Sprint 19 — Quenyx AI
 * Platform Foundation). It carries only the inputs every module needs; module-specific meaning is
 * interpreted by the adapter. Pure data — no AI, no DB.
 */
final readonly class AiModuleRequest
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $moduleKey,
        public int $projectId,
        public string $query,
        public ?string $framework = null,
        public ?string $release = null,
        public ?string $code = null,
        public array $options = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $moduleKey, int $projectId, array $data): self
    {
        return new self(
            moduleKey: $moduleKey,
            projectId: $projectId,
            query: (string) ($data['query'] ?? $data['message'] ?? ''),
            framework: self::str($data['framework'] ?? null),
            release: self::str($data['release'] ?? null),
            code: self::str($data['code'] ?? null),
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

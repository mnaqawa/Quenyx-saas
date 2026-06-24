<?php

namespace App\DataTransferObjects\Ai;

use Illuminate\Support\Str;

/**
 * A request to execute a skill. Carries an optional explicit skill key, the context type, the
 * framework/release scope, and free-form parameters. Each request gets a UUID + timestamp so
 * executions are traceable. No provider/AI fields — skills never call a model.
 */
final readonly class AiSkillRequest
{
    public string $uuid;

    public string $requestedAt;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public ?string $skill = null,
        public ?string $contextType = null,
        public ?string $frameworkKey = null,
        public ?string $releaseCode = null,
        public array $parameters = [],
        ?string $uuid = null,
    ) {
        $this->uuid = $uuid ?? (string) Str::uuid();
        $this->requestedAt = now()->toIso8601String();
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    public function stringParam(string $key, ?string $default = null): ?string
    {
        $value = $this->parameters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'skill' => $this->skill,
            'context_type' => $this->contextType,
            'framework' => $this->frameworkKey,
            'release' => $this->releaseCode,
            'parameters' => $this->parameters,
            'requested_at' => $this->requestedAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Automation;

use App\Models\Project;
use App\Models\User;

/**
 * Sprint 23 — the immutable input an execution adapter receives.
 *
 * Carries everything an adapter needs to PLAN (dry-run) or PERFORM (live) one action: the workspace,
 * the requesting user (nullable for system/scheduled triggers), the resolved parameters, the target,
 * and the safety envelope (mode, timeout, retries). Adapters MUST honor {@see self::$mode}: in
 * `dry_run` they return a deterministic plan and perform NO side effects.
 */
final class ExecutionContext
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Project $project,
        public readonly ?User $user,
        public readonly string $adapterKey,
        public readonly ?string $actionKey,
        public readonly array $parameters = [],
        public readonly array $target = [],
        public readonly string $mode = 'dry_run',
        public readonly int $timeoutSeconds = 60,
        public readonly int $maxRetries = 0,
        public readonly array $metadata = [],
    ) {}

    public function isDryRun(): bool
    {
        return $this->mode !== 'live';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withMode(string $mode): self
    {
        return new self(
            $this->project,
            $this->user,
            $this->adapterKey,
            $this->actionKey,
            $this->parameters,
            $this->target,
            $mode,
            $this->timeoutSeconds,
            $this->maxRetries,
            $this->metadata,
        );
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }
}

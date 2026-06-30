<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Automation;

/**
 * Sprint 23 — the immutable outcome an execution adapter returns.
 *
 * Statuses:
 *  - `dry_run`   — a deterministic plan was produced; NO side effect occurred (the safe default).
 *  - `succeeded` — a live action completed successfully.
 *  - `failed`    — a live action was attempted and failed (error captured, never thrown to the user).
 *  - `skipped`   — live execution was requested but the adapter is not operational (honest report,
 *                  e.g. no runner/credentials configured); NO side effect occurred.
 *
 * `rollbackToken` carries adapter-specific data needed to undo a successful live action.
 */
final class ExecutionResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const DRY_RUN = 'dry_run';
    public const SKIPPED = 'skipped';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $rollbackToken
     */
    public function __construct(
        public readonly string $status,
        public readonly string $output,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly ?array $rollbackToken = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function dryRun(string $output, array $data = []): self
    {
        return new self(self::DRY_RUN, $output, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $rollbackToken
     */
    public static function success(string $output, array $data = [], ?array $rollbackToken = null): self
    {
        return new self(self::SUCCEEDED, $output, $data, null, $rollbackToken);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function failure(string $output, string $error, array $data = []): self
    {
        return new self(self::FAILED, $output, $data, $error);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function skipped(string $output, array $data = []): self
    {
        return new self(self::SKIPPED, $output, $data);
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'output' => $this->output,
            'data' => $this->data,
            'error' => $this->error,
            'rollback_token' => $this->rollbackToken,
        ];
    }
}

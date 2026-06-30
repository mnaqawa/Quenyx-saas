<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\Contracts\Automation\ExecutionAdapter;
use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — backward-compatible base for execution adapters.
 *
 * Supplies safe defaults: dry-run + timeout + retry capabilities, no rollback, and an honest
 * "not operational" fallback for live execution. Concrete adapters override only what they support.
 * The master safety switch is `config('automation.live_execution')`: when off (the default) NO
 * adapter performs a live side effect anywhere in the platform.
 */
abstract class AbstractExecutionAdapter implements ExecutionAdapter
{
    public function description(): string
    {
        return $this->name().' execution adapter.';
    }

    public function category(): string
    {
        return 'generic';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        $caps = ['dry_run', 'timeout', 'retry'];
        if ($this->supportsRollback()) {
            $caps[] = 'rollback';
        }

        return $caps;
    }

    public function supportsRollback(): bool
    {
        return false;
    }

    public function isOperational(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $rollbackToken
     */
    public function rollback(ExecutionContext $context, array $rollbackToken): ExecutionResult
    {
        return ExecutionResult::skipped(
            sprintf('Rollback is not supported by the %s adapter.', $this->name())
        );
    }

    /** Whether live execution is permitted platform-wide (master safety switch). */
    protected function liveAllowed(): bool
    {
        return (bool) config('automation.live_execution', false);
    }
}

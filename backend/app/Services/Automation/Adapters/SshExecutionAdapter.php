<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — SSH execution adapter (remote Linux/Unix command execution).
 *
 * Real remote execution requires a credentialed SSH runner that is not present by default. Until one
 * is configured the adapter produces a deterministic plan and honestly skips live requests — it never
 * fabricates remote output. Supports a rollback command for reversible operations.
 */
class SshExecutionAdapter extends AbstractExecutionAdapter
{
    public function key(): string
    {
        return 'ssh';
    }

    public function name(): string
    {
        return 'SSH';
    }

    public function description(): string
    {
        return 'Execute a command on a remote host over SSH through a credentialed runner.';
    }

    public function category(): string
    {
        return 'remote_shell';
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function isOperational(): bool
    {
        return $this->liveAllowed() && (bool) config('automation.ssh.runner_enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [
            'host' => ['type' => 'string', 'required' => true],
            'command' => ['type' => 'string', 'required' => true],
            'rollback_command' => ['type' => 'string', 'required' => false],
        ];
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $host = (string) $context->param('host', (string) ($context->target['host'] ?? ''));
        $command = (string) $context->param('command', '');

        if ($context->isDryRun()) {
            return ExecutionResult::dryRun(
                sprintf('PLAN: ssh %s -- %s (dry-run, nothing executed).', $host ?: '<host>', $command),
                ['host' => $host, 'command' => $command],
            );
        }

        return ExecutionResult::skipped(
            'Live SSH execution requires a configured SSH runner (automation.ssh.runner_enabled). No command was run.',
            ['host' => $host, 'command' => $command, 'reason' => 'no_runner'],
        );
    }

    /**
     * @param  array<string, mixed>  $rollbackToken
     */
    public function rollback(ExecutionContext $context, array $rollbackToken): ExecutionResult
    {
        $command = (string) ($rollbackToken['rollback_command'] ?? $context->param('rollback_command', ''));
        if ($command === '') {
            return ExecutionResult::skipped('No rollback command was provided for this SSH action.');
        }

        return ExecutionResult::skipped(
            'Rollback would run the provided SSH command once a runner is configured.',
            ['rollback_command' => $command],
        );
    }
}

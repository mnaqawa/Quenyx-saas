<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — PowerShell execution adapter (remote Windows command execution via WinRM/runner).
 *
 * Like SSH, real execution requires a credentialed runner that is not present by default; the adapter
 * plans deterministically and honestly skips live requests until one is configured.
 */
class PowerShellExecutionAdapter extends AbstractExecutionAdapter
{
    public function key(): string
    {
        return 'powershell';
    }

    public function name(): string
    {
        return 'PowerShell';
    }

    public function description(): string
    {
        return 'Execute a PowerShell command on a Windows host through a credentialed runner.';
    }

    public function category(): string
    {
        return 'remote_shell';
    }

    public function isOperational(): bool
    {
        return $this->liveAllowed() && (bool) config('automation.powershell.runner_enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [
            'host' => ['type' => 'string', 'required' => true],
            'command' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $host = (string) $context->param('host', (string) ($context->target['host'] ?? ''));
        $command = (string) $context->param('command', '');

        if ($context->isDryRun()) {
            return ExecutionResult::dryRun(
                sprintf('PLAN: powershell @%s -- %s (dry-run, nothing executed).', $host ?: '<host>', $command),
                ['host' => $host, 'command' => $command],
            );
        }

        return ExecutionResult::skipped(
            'Live PowerShell execution requires a configured runner (automation.powershell.runner_enabled). '
            .'No command was run.',
            ['host' => $host, 'command' => $command, 'reason' => 'no_runner'],
        );
    }
}

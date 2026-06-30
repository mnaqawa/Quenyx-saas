<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — Script execution adapter (shell/python/etc. via a managed runner).
 *
 * Arbitrary local script execution is intentionally NOT performed inside the application process.
 * Live execution requires a dedicated, sandboxed runner; until one is configured this adapter reports
 * the plan honestly and skips live requests rather than fabricating a result. This is the same
 * honesty rule the AI platform uses for uncollected data.
 */
class ScriptExecutionAdapter extends AbstractExecutionAdapter
{
    public function key(): string
    {
        return 'script';
    }

    public function name(): string
    {
        return 'Script';
    }

    public function description(): string
    {
        return 'Run a script (shell/python/etc.) through a sandboxed automation runner.';
    }

    public function category(): string
    {
        return 'script';
    }

    public function isOperational(): bool
    {
        return $this->liveAllowed() && (bool) config('automation.script.runner_enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [
            'interpreter' => ['type' => 'string', 'required' => false, 'enum' => ['bash', 'sh', 'python', 'powershell']],
            'script' => ['type' => 'string', 'required' => true],
            'args' => ['type' => 'array', 'required' => false],
        ];
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $interpreter = (string) $context->param('interpreter', 'bash');
        $script = (string) $context->param('script', '');
        $preview = mb_substr($script, 0, 400);

        if ($context->isDryRun()) {
            return ExecutionResult::dryRun(
                sprintf('PLAN: run %s script (%d chars) — dry-run, nothing executed.', $interpreter, mb_strlen($script)),
                ['interpreter' => $interpreter, 'script_preview' => $preview],
            );
        }

        return ExecutionResult::skipped(
            'Live script execution requires a configured sandboxed runner (automation.script.runner_enabled). '
            .'No script was executed.',
            ['interpreter' => $interpreter, 'script_preview' => $preview, 'reason' => 'no_runner'],
        );
    }
}

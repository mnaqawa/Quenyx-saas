<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — Webhook execution adapter. POSTs a JSON payload to an allowlisted webhook URL.
 * Safe by default (dry-run plan unless live + allowlisted host).
 */
class WebhookExecutionAdapter extends AbstractHttpExecutionAdapter
{
    public function key(): string
    {
        return 'webhook';
    }

    public function name(): string
    {
        return 'Webhook';
    }

    public function description(): string
    {
        return 'POST a JSON payload to an allowlisted webhook endpoint (e.g. ChatOps, ticketing).';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [
            'url' => ['type' => 'string', 'required' => true],
            'payload' => ['type' => 'object', 'required' => false],
            'headers' => ['type' => 'object', 'required' => false],
        ];
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $url = (string) $context->param('url', '');
        $payload = (array) $context->param('payload', []);
        $headers = (array) $context->param('headers', []);

        if ($url === '') {
            return ExecutionResult::failure('Webhook not sent', 'A webhook URL is required.');
        }

        if ($context->isDryRun() || ! $this->isOperational()) {
            return ExecutionResult::dryRun(
                sprintf('PLAN: POST webhook → %s (dry-run — no request sent).', $url),
                ['url' => $url, 'host_allowed' => $this->hostAllowed($url), 'operational' => $this->isOperational()],
            );
        }

        return $this->performHttp('POST', $url, $headers, $payload, $context);
    }
}

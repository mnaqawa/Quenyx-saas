<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — REST API execution adapter. Calls an HTTP endpoint with a method, headers, and body.
 * Safe by default (dry-run plan unless live + allowlisted host).
 */
class RestExecutionAdapter extends AbstractHttpExecutionAdapter
{
    public function key(): string
    {
        return 'rest';
    }

    public function name(): string
    {
        return 'REST API';
    }

    public function description(): string
    {
        return 'Invoke a REST endpoint (method, headers, JSON body) against an allowlisted host.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [
            'method' => ['type' => 'string', 'required' => true, 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
            'url' => ['type' => 'string', 'required' => true],
            'headers' => ['type' => 'object', 'required' => false],
            'body' => ['type' => 'object', 'required' => false],
        ];
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $method = strtoupper((string) $context->param('method', 'GET'));
        $url = (string) $context->param('url', '');
        $headers = (array) $context->param('headers', []);
        $body = $context->param('body');

        if ($url === '') {
            return ExecutionResult::failure('REST request not run', 'A target URL is required.');
        }

        if ($context->isDryRun() || ! $this->isOperational()) {
            return ExecutionResult::dryRun(
                sprintf('PLAN: %s %s (dry-run — no request sent).', $method, $url),
                ['method' => $method, 'url' => $url, 'host_allowed' => $this->hostAllowed($url), 'operational' => $this->isOperational()],
            );
        }

        return $this->performHttp($method, $url, $headers, $body, $context);
    }
}

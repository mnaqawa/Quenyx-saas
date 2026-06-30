<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sprint 23 — shared HTTP execution behavior for the REST and Webhook adapters.
 *
 * Safe by default: a live HTTP call is only attempted when (a) the platform live switch is on AND
 * (b) the target host is on the workspace allowlist (`automation.http.allowed_hosts`). Otherwise the
 * adapter returns a deterministic dry-run plan, or — for an explicit live request it cannot satisfy —
 * an honest `skipped` result. It never performs destructive HTTP automatically.
 */
abstract class AbstractHttpExecutionAdapter extends AbstractExecutionAdapter
{
    public function category(): string
    {
        return 'http';
    }

    public function isOperational(): bool
    {
        return $this->liveAllowed() && ! empty(config('automation.http.allowed_hosts', []));
    }

    /**
     * @return array<string, mixed>
     */
    protected function performHttp(string $method, string $url, array $headers, mixed $body, ExecutionContext $context): ExecutionResult
    {
        if (! $this->hostAllowed($url)) {
            return ExecutionResult::skipped(sprintf(
                'Live HTTP execution to "%s" was not performed: the host is not on automation.http.allowed_hosts.',
                $this->hostOf($url)
            ), ['url' => $url, 'reason' => 'host_not_allowed']);
        }

        try {
            $request = Http::timeout(max(1, $context->timeoutSeconds))->withHeaders($headers);
            $response = $request->send(strtoupper($method), $url, is_array($body) ? ['json' => $body] : ['body' => (string) $body]);

            $summary = sprintf('%s %s → HTTP %d', strtoupper($method), $url, $response->status());

            if ($response->successful()) {
                return ExecutionResult::success($summary, [
                    'status_code' => $response->status(),
                    'body_preview' => mb_substr((string) $response->body(), 0, 2000),
                ], ['method' => $method, 'url' => $url]);
            }

            return ExecutionResult::failure($summary, 'Non-2xx response: '.$response->status(), [
                'status_code' => $response->status(),
                'body_preview' => mb_substr((string) $response->body(), 0, 2000),
            ]);
        } catch (Throwable $e) {
            return ExecutionResult::failure(
                sprintf('%s %s failed', strtoupper($method), $url),
                $e->getMessage(),
            );
        }
    }

    protected function hostAllowed(string $url): bool
    {
        $host = $this->hostOf($url);
        if ($host === '') {
            return false;
        }

        foreach ((array) config('automation.http.allowed_hosts', []) as $allowed) {
            if (strcasecmp((string) $allowed, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function hostOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? '');
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Domain exception for OpenAI Responses API failures.
 *
 * Carries a stable machine-readable error code and the HTTP status the
 * controller should surface, so the provider's raw exceptions never leak
 * to API clients.
 */
class OpenAIServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 502,
        public readonly string $errorCode = 'ai_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

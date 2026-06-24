<?php

namespace App\Exceptions\Ai;

use Exception;

/**
 * Raised for AI provider/registry problems: unknown provider, missing implementation,
 * misconfiguration, or upstream failure. Mapped to HTTP 422/502 by the controller.
 */
class AiProviderException extends Exception
{
    public function __construct(
        string $message = 'AI provider error.',
        private readonly string $errorCode = 'ai_provider_error',
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

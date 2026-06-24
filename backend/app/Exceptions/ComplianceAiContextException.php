<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when an AI consumption-contract payload cannot be assembled deterministically
 * or fails a guardrail/validation invariant (e.g. missing citations, missing source
 * document, missing bilingual text, or an unsupported context type).
 *
 * This is a contract-layer validation error (HTTP 422). It never represents an AI call,
 * because the AI Consumption Contract Layer performs no AI execution.
 */
class ComplianceAiContextException extends Exception
{
    public function __construct(
        string $message = 'AI context payload is invalid.',
        private readonly string $errorCode = 'ai_context_invalid',
    ) {
        parent::__construct($message, 422);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

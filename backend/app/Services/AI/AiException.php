<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Domain exception for AI agent failures. Carries an HTTP status so the
 * controller can surface an accurate, actionable error to the client
 * instead of masking the failure.
 */
class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 502,
        public readonly string $errorCode = 'ai_error',
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(
            'The AI agent is not enabled. Set AI_ENABLED=true and configure AI_API_KEY.',
            503,
            'ai_disabled',
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            'The AI provider is not configured. Set AI_API_KEY (and AI_BASE_URL / AI_MODEL).',
            503,
            'ai_not_configured',
        );
    }

    public static function upstream(string $detail): self
    {
        return new self('AI provider request failed: '.$detail, 502, 'ai_upstream_error');
    }
}

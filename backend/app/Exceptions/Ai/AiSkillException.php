<?php

namespace App\Exceptions\Ai;

use Exception;

/**
 * Raised for AI Skills Framework problems: unknown/disabled skill, no matching skill, missing
 * required parameters, or a skill-level execution failure. Mapped to an HTTP status by the
 * controller.
 */
class AiSkillException extends Exception
{
    public function __construct(
        string $message = 'AI skill error.',
        private readonly string $errorCode = 'ai_skill_error',
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

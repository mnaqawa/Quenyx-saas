<?php

namespace App\DataTransferObjects\Ai;

/**
 * The envelope returned to callers (and aggregated by the router): success flag, the execution
 * trace, the result on success, or an error on failure. This is the ONLY thing the skills API
 * returns — never an AI provider response.
 */
final readonly class AiSkillResponse
{
    public function __construct(
        public string $skillKey,
        public bool $success,
        public AiSkillExecution $execution,
        public ?AiSkillResult $result = null,
        public ?string $error = null,
        public ?string $errorCode = null,
    ) {}

    public static function completed(AiSkillExecution $execution, AiSkillResult $result): self
    {
        return new self($execution->skillKey, true, $execution, $result);
    }

    public static function failed(AiSkillExecution $execution, string $error, string $errorCode): self
    {
        return new self($execution->skillKey, false, $execution, null, $error, $errorCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skillKey,
            'success' => $this->success,
            'execution' => $this->execution->toArray(),
            'result' => $this->result?->toArray(),
            'error' => $this->error,
            'error_code' => $this->errorCode,
        ];
    }
}

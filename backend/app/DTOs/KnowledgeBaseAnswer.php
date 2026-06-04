<?php

namespace App\DTOs;

/**
 * Immutable result of an OpenAI Responses API + File Search query.
 */
class KnowledgeBaseAnswer
{
    public function __construct(
        public readonly string $answer,
        public readonly string $agentType,
        public readonly string $model,
        public readonly ?string $responseId = null,
        public readonly ?int $totalTokens = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'agent' => $this->agentType,
            'model' => $this->model,
            'response_id' => $this->responseId,
            'total_tokens' => $this->totalTokens,
        ];
    }
}

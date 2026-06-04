<?php

namespace App\Http\Resources;

use App\DTOs\KnowledgeBaseAnswer;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property KnowledgeBaseAnswer $resource
 */
class AIAgentAnswerResource extends JsonResource
{
    /**
     * Disable the default "data" envelope so the payload is the documented
     * { "success": true, "answer": "..." } shape.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var KnowledgeBaseAnswer $answer */
        $answer = $this->resource;

        return [
            'success' => true,
            'answer' => $answer->answer,
            'agent' => $answer->agentType,
            'meta' => [
                'model' => $answer->model,
                'response_id' => $answer->responseId,
                'total_tokens' => $answer->totalTokens,
            ],
        ];
    }
}

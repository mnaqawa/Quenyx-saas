<?php

namespace App\Http\Resources\Ai;

use App\Models\Ai\AiConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sprint 20 — UUID-only conversation representation. Numeric primary keys are never exposed.
 *
 * @mixin AiConversation
 */
class AiConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status,
            'message_count' => (int) $this->message_count,
            'prompt_tokens' => (int) $this->prompt_tokens,
            'completion_tokens' => (int) $this->completion_tokens,
            'total_tokens' => (int) $this->total_tokens,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'title' => is_array($this->metadata) ? ($this->metadata['title'] ?? null) : null,
            'messages' => AiConversationMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}

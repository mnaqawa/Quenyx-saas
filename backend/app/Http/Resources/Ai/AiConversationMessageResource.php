<?php

namespace App\Http\Resources\Ai;

use App\Models\Ai\AiConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sprint 20 — UUID-only message representation. `content` is only present when prompt logging is
 * enabled (otherwise it was never stored); callers must tolerate null content.
 *
 * @mixin AiConversationMessage
 */
class AiConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'role' => $this->role,
            'content' => $this->content,
            'prompt_tokens' => (int) $this->prompt_tokens,
            'completion_tokens' => (int) $this->completion_tokens,
            'total_tokens' => (int) $this->total_tokens,
            'mocked' => (bool) $this->mocked,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

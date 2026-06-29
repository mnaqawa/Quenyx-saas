<?php

namespace App\Http\Resources\Ai;

use App\Models\Ai\AiPromptTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sprint 20 — UUID-only prompt template representation.
 *
 * @mixin AiPromptTemplate
 */
class AiPromptTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'body' => $this->body,
            'variables' => $this->variables ?? [],
            'is_shared' => (bool) $this->is_shared,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

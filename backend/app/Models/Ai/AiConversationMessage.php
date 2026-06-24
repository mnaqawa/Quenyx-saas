<?php

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiConversationMessage extends Model
{
    protected $table = 'ai_conversation_messages';

    protected $fillable = [
        'uuid',
        'ai_conversation_id',
        'role',
        'content',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'mocked',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'mocked' => 'boolean',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}

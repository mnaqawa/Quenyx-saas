<?php

namespace App\Models\Ai;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'uuid',
        'project_id',
        'user_id',
        'provider',
        'model',
        'status',
        'message_count',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'usage_metadata',
        'metadata',
    ];

    protected $casts = [
        'usage_metadata' => 'array',
        'metadata' => 'array',
        'message_count' => 'integer',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiConversationMessage::class, 'ai_conversation_id');
    }
}

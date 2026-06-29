<?php

namespace App\Models\Ai;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 20 — workspace-scoped reusable prompt template. UUID-addressed, fully audited.
 */
class AiPromptTemplate extends Model
{
    protected $table = 'ai_prompt_templates';

    protected $fillable = [
        'uuid',
        'project_id',
        'created_by',
        'updated_by',
        'name',
        'description',
        'category',
        'body',
        'variables',
        'is_shared',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_shared' => 'boolean',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

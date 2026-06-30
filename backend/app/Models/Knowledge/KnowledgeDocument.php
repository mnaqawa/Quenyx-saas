<?php

namespace App\Models\Knowledge;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 24 — a knowledge document. Either authored in the Internal Knowledge Base or indexed from a
 * registered Knowledge Source (`source_key`). Workspace-scoped, UUID-addressed, real indexed content
 * only — never fabricated.
 */
class KnowledgeDocument extends Model
{
    protected $table = 'knowledge_documents';

    protected $fillable = [
        'uuid', 'project_id', 'author_id', 'source_key', 'external_ref', 'title', 'slug',
        'format', 'category', 'status', 'body', 'tags', 'metadata', 'indexed_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->indexed_at)) {
                $model->indexed_at = now();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

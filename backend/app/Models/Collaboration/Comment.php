<?php

namespace App\Models\Collaboration;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 24 — a collaboration comment on ANY entity (incident/ticket/document/execution/asset/…),
 * addressed polymorphically by (entity_type, entity_uuid). Reusable by every module — there is no
 * per-module comment system.
 */
class Comment extends Model
{
    protected $table = 'collaboration_comments';

    protected $fillable = [
        'uuid', 'project_id', 'author_id', 'entity_type', 'entity_uuid', 'body', 'mentions',
    ];

    protected $casts = [
        'mentions' => 'array',
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

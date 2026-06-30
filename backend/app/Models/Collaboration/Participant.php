<?php

namespace App\Models\Collaboration;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 24 — a collaboration participant (watcher/assignee/owner) on any entity. Reusable layer for
 * task ownership, watchers, and shared investigations across every module.
 */
class Participant extends Model
{
    protected $table = 'collaboration_participants';

    protected $fillable = [
        'project_id', 'user_id', 'entity_type', 'entity_uuid', 'role',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

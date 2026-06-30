<?php

namespace App\Models\Automation;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 23 — a workspace-scoped runbook (named, ordered set of automation steps). May be authored
 * manually or AI-assisted; AI-assisted runbooks are always editable drafts and are NEVER auto-executed.
 */
class AutomationRunbook extends Model
{
    protected $table = 'automation_runbooks';

    protected $fillable = [
        'uuid', 'project_id', 'created_by', 'name', 'category',
        'description', 'source', 'status', 'definition',
    ];

    protected $casts = [
        'definition' => 'array',
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
}

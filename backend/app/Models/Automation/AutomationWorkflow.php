<?php

namespace App\Models\Automation;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 23 — a workspace-scoped automation workflow (trigger → conditions → actions → approval →
 * execution → verification → notification → audit). Definition is data-only; execution is driven by
 * the registry-based Execution Engine.
 */
class AutomationWorkflow extends Model
{
    protected $table = 'automation_workflows';

    protected $fillable = [
        'uuid', 'project_id', 'created_by', 'name', 'description',
        'trigger_type', 'schedule', 'enabled', 'requires_approval', 'definition',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'requires_approval' => 'boolean',
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

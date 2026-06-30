<?php

namespace App\Models\Automation;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 23 — a human approval gate for an automation execution. Live execution cannot proceed until
 * an authorized operator approves; rejection cancels the execution. Fully audited.
 */
class AutomationApproval extends Model
{
    protected $table = 'automation_approvals';

    protected $fillable = [
        'uuid', 'project_id', 'execution_id', 'requested_by', 'decided_by',
        'status', 'reason', 'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
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

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

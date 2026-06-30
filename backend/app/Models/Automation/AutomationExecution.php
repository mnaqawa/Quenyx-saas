<?php

namespace App\Models\Automation;

use App\Models\Incident\Incident;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Sprint 23 — a single automation execution record. Safe by default: `mode` is `dry_run` unless live
 * execution is explicitly enabled and approved. Every execution is fully audited and may be rolled back.
 */
class AutomationExecution extends Model
{
    protected $table = 'automation_executions';

    protected $fillable = [
        'uuid', 'project_id', 'workflow_id', 'runbook_id', 'incident_id',
        'requested_by', 'approved_by', 'adapter_key', 'action_key', 'status', 'mode',
        'timeout_seconds', 'max_retries', 'parameters', 'context', 'result', 'error',
        'rolled_back', 'started_at', 'finished_at', 'duration_ms',
    ];

    protected $casts = [
        'parameters' => 'array',
        'context' => 'array',
        'result' => 'array',
        'rolled_back' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
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

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function runbook(): BelongsTo
    {
        return $this->belongsTo(AutomationRunbook::class, 'runbook_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationExecutionStep::class, 'execution_id')->orderBy('step_index');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(AutomationApproval::class, 'execution_id');
    }
}

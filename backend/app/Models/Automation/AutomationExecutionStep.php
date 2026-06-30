<?php

namespace App\Models\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 23 — a single step within an automation execution (per-action audit trail).
 */
class AutomationExecutionStep extends Model
{
    protected $table = 'automation_execution_steps';

    protected $fillable = [
        'execution_id', 'step_index', 'name', 'status', 'output', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }
}

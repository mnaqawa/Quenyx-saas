<?php

namespace App\Models\Automation;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 23 — an immutable, auditable record of an automation outcome. These records are the ONLY
 * "learning" in the platform: future AI recommendations cite aggregated historical outcomes. There is
 * no model training, no hidden state — every record is inspectable and workspace-scoped.
 */
class AutomationLearningRecord extends Model
{
    protected $table = 'automation_learning_records';

    protected $fillable = [
        'project_id', 'execution_id', 'recommendation_key', 'action_key',
        'outcome', 'duration_ms', 'operator_feedback', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }
}

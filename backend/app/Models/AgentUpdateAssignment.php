<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentUpdateAssignment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_update_assignments';

    protected $fillable = [
        'id',
        'agent_id',
        'release_id',
        'workspace_id',
        'status',
        'progress',
        'result',
        'approved',
        'rollback_allowed',
        'scheduled_at',
        'maintenance_window_start',
        'maintenance_window_end',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'approved' => 'boolean',
        'rollback_allowed' => 'boolean',
        'progress' => 'integer',
        'scheduled_at' => 'datetime',
        'maintenance_window_start' => 'datetime',
        'maintenance_window_end' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(AgentRelease::class, 'release_id');
    }
}

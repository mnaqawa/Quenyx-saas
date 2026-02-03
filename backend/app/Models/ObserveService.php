<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveService extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'engine_key',
        'engine_service_key',
        'host_name',
        'service_name',
        'state',
        'last_check_at',
        'next_check_at',
        'duration_sec',
        'attempt',
        'current_attempt',
        'max_attempts',
        'state_type',
        'output',
        'plugin_output',
        'long_plugin_output',
        'perfdata',
        'check_command',
        'check_latency_sec',
        'execution_time_sec',
        'last_state_change_at',
        'check_interval',
        'retry_interval',
    ];

    protected $casts = [
        'last_check_at' => 'datetime',
        'next_check_at' => 'datetime',
        'last_state_change_at' => 'datetime',
        'duration_sec' => 'integer',
        'current_attempt' => 'integer',
        'max_attempts' => 'integer',
        'check_latency_sec' => 'float',
        'execution_time_sec' => 'float',
        'check_interval' => 'integer',
        'retry_interval' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}

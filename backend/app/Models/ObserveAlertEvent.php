<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveAlertEvent extends Model
{
    protected $fillable = [
        'workspace_id',
        'alert_rule_id',
        'target_host_id',
        'target_service_key',
        'host_name',
        'service_name',
        'severity',
        'title',
        'message',
        'status',
        'triggered_at',
        'opened_at',
        'last_seen_at',
        'occurrence_count',
        'resolved_at',
        'acknowledged_at',
        'metadata',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'opened_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'occurrence_count' => 'integer',
        'metadata' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ObserveAlertRule::class, 'alert_rule_id');
    }
}

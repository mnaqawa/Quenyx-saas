<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveAlertEvent extends Model
{
    protected $fillable = [
        'workspace_id',
        'alert_rule_id',
        'severity',
        'title',
        'message',
        'status',
        'triggered_at',
        'resolved_at',
        'acknowledged_at',
        'metadata',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObserveAlertRule extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'severity',
        'target_scope',
        'target_host_id',
        'target_service_key',
        'metric_condition',
        'operator',
        'threshold_value',
        'duration_seconds',
        'notification_channel',
        'enabled',
        'last_triggered_at',
        'trigger_count_7d',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'threshold_value' => 'float',
        'last_triggered_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ObserveAlertEvent::class, 'alert_rule_id');
    }
}

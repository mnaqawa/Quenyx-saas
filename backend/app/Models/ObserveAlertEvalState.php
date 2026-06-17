<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveAlertEvalState extends Model
{
    protected $fillable = [
        'workspace_id',
        'alert_rule_id',
        'target_host_id',
        'target_service_key',
        'host_name',
        'service_name',
        'condition_met_since',
        'last_evaluated_at',
        'last_value',
    ];

    protected $casts = [
        'condition_met_since' => 'datetime',
        'last_evaluated_at' => 'datetime',
        'last_value' => 'float',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ObserveAlertRule::class, 'alert_rule_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveMetricHistory extends Model
{
    protected $table = 'observe_metrics_history';

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'host_name',
        'service_name',
        'metric',
        'value',
        'recorded_at',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'value' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}

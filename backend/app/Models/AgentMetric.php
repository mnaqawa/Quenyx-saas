<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMetric extends Model
{
    protected $table = 'agent_metrics';

    protected $fillable = ['agent_id', 'collected_at', 'payload'];

    protected $casts = [
        'collected_at' => 'datetime',
        'payload' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

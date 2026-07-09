<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOfflineEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_offline_events';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'agent_id',
        'workspace_id',
        'event_type',
        'dedup_key',
        'payload',
        'event_at',
        'ingested_at',
        'source',
    ];

    protected $casts = [
        'payload' => 'array',
        'event_at' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

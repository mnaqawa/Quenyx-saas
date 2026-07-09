<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentUpdateHistory extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_update_history';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'agent_id',
        'workspace_id',
        'from_version',
        'to_version',
        'channel',
        'status',
        'result',
        'rollback',
        'detail',
        'recorded_at',
    ];

    protected $casts = [
        'rollback' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

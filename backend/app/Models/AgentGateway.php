<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentGateway extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_gateways';

    protected $fillable = [
        'id',
        'workspace_id',
        'name',
        'region',
        'endpoint_url',
        'version',
        'health_status',
        'capacity',
        'connected_agents',
        'is_primary',
        'last_heartbeat_at',
        'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'preferred_gateway_id');
    }
}

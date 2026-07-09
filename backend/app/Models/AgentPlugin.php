<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPlugin extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_plugins';

    protected $fillable = [
        'id',
        'agent_id',
        'plugin_key',
        'name',
        'version',
        'vendor',
        'description',
        'status',
        'health_status',
        'last_execution_at',
        'error_count',
        'required_permissions',
        'dependencies',
        'configuration_version',
        'metadata',
    ];

    protected $casts = [
        'last_execution_at' => 'datetime',
        'required_permissions' => 'array',
        'dependencies' => 'array',
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentConfigurationRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_configuration_revisions';

    protected $fillable = [
        'id',
        'workspace_id',
        'version',
        'status',
        'settings',
        'created_by',
        'rollback_of_version',
        'published_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'published_at' => 'datetime',
    ];
}

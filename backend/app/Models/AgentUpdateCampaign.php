<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentUpdateCampaign extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_update_campaigns';

    protected $fillable = [
        'id',
        'workspace_id',
        'release_id',
        'name',
        'channel',
        'status',
        'mandatory',
        'require_approval',
        'maintenance_window_start',
        'maintenance_window_end',
        'created_by',
        'target_filters',
    ];

    protected $casts = [
        'mandatory' => 'boolean',
        'require_approval' => 'boolean',
        'maintenance_window_start' => 'datetime',
        'maintenance_window_end' => 'datetime',
        'target_filters' => 'array',
    ];
}

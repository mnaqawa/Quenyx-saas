<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAsset extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'platform_assets';

    protected $fillable = [
        'id',
        'workspace_id',
        'agent_id',
        'managed_resource_id',
        'monitoring_target_id',
        'name',
        'asset_type',
        'lifecycle_status',
        'health_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function managedResource(): BelongsTo
    {
        return $this->belongsTo(AgentManagedResource::class, 'managed_resource_id');
    }

    public function monitoringTarget(): BelongsTo
    {
        return $this->belongsTo(ObserveTargetHost::class, 'monitoring_target_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentManagedResource extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_managed_resources';

    protected $fillable = [
        'id',
        'agent_id',
        'workspace_id',
        'resource_type',
        'display_name',
        'parent_resource_id',
        'lifecycle_status',
        'health_status',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_resource_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_resource_id');
    }

    public function platformAsset(): HasOne
    {
        return $this->hasOne(PlatformAsset::class, 'managed_resource_id');
    }
}

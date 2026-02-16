<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Agent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workspace_id',
        'enrollment_token_id',
        'hostname',
        'os',
        'arch',
        'agent_version',
        'primary_protocol',
        'enabled_protocols',
        'permissions',
        'agent_secret_hash',
        'last_seen_at',
        'status',
        'enrolled_at',
    ];

    protected $casts = [
        'enabled_protocols' => 'array',
        'permissions' => 'array',
        'last_seen_at' => 'datetime',
        'enrolled_at' => 'datetime',
    ];

    protected $hidden = ['agent_secret_hash'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function enrollmentToken(): BelongsTo
    {
        return $this->belongsTo(AgentEnrollmentToken::class, 'enrollment_token_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AgentMetric::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(AgentInventory::class);
    }

    public function observeHosts(): HasMany
    {
        return $this->hasMany(ObserveTargetHost::class, 'agent_id');
    }

    public static function generateId(): string
    {
        return (string) Str::uuid();
    }

    public function markSeen(): void
    {
        $this->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);
    }

    public function hasProtocol(string $protocol): bool
    {
        $enabled = $this->enabled_protocols ?? [$this->primary_protocol ?? 'http_api'];

        return in_array($protocol, $enabled, true);
    }
}

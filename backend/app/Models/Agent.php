<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Agent extends Model
{
    use SoftDeletes;
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
        'capabilities',
        'enabled_modules',
        'private_ips',
        'public_ip',
        'observed_source_ip',
        'interfaces',
        'region',
        'cloud_provider',
        'availability_zone',
        'nat_detected',
        'vpn_detected',
        'workspace_uuid',
        'agent_secret_hash',
        'last_seen_at',
        'status',
        'lifecycle_status',
        'policy_version',
        'platform_version',
        'policy_status',
        'capability_hash',
        'plugin_versions',
        'preferred_gateway_id',
        'last_error',
        'heartbeat_count',
        'bytes_sent',
        'bytes_received',
        'revoked_at',
        'revoked_reason',
        'enrolled_at',
        'update_channel',
        'update_status',
        'update_progress',
        'config_version',
        'health_score',
        'health_level',
        'health_breakdown',
        'queue_stats',
        'certificate_fingerprint',
    ];

    protected $casts = [
        'enabled_protocols' => 'array',
        'permissions' => 'array',
        'capabilities' => 'array',
        'enabled_modules' => 'array',
        'private_ips' => 'array',
        'interfaces' => 'array',
        'nat_detected' => 'boolean',
        'vpn_detected' => 'boolean',
        'plugin_versions' => 'array',
        'heartbeat_count' => 'integer',
        'bytes_sent' => 'integer',
        'bytes_received' => 'integer',
        'last_seen_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'revoked_at' => 'datetime',
        'health_score' => 'integer',
        'health_breakdown' => 'array',
        'queue_stats' => 'array',
        'update_progress' => 'integer',
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

    public function managedResources(): HasMany
    {
        return $this->hasMany(AgentManagedResource::class);
    }

    public function plugins(): HasMany
    {
        return $this->hasMany(AgentPlugin::class);
    }

    public function platformAssets(): HasMany
    {
        return $this->hasMany(PlatformAsset::class);
    }

    public function preferredGateway(): BelongsTo
    {
        return $this->belongsTo(AgentGateway::class, 'preferred_gateway_id');
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
            'lifecycle_status' => $this->lifecycle_status === 'revoked' ? 'revoked' : 'online',
            'heartbeat_count' => ($this->heartbeat_count ?? 0) + 1,
        ]);
    }

    public function hasProtocol(string $protocol): bool
    {
        $enabled = $this->enabled_protocols ?? [$this->primary_protocol ?? 'http_api'];

        return in_array($protocol, $enabled, true);
    }
}

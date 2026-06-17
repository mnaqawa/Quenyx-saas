<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AgentEnrollmentToken extends Model
{
    protected $table = 'agent_enrollment_tokens';

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'token_hash',
        'allowed_hostname',
        'target_os',
        'expires_at',
        'primary_protocol',
        'enabled_protocols',
        'permissions',
        'used_at',
        'revoked_at',
        'last_used_at',
        'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'enabled_protocols' => 'array',
        'permissions' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return (bool) $this->used_at;
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null || ($this->status ?? 'active') === 'revoked') {
            return false;
        }

        return ! $this->isExpired() && ! $this->isUsed();
    }

    public static function generateToken(): string
    {
        return 'ps_' . Str::random(48);
    }
}

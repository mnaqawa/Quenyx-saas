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
        'name',
        'token_hash',
        'expires_at',
        'primary_protocol',
        'enabled_protocols',
        'permissions',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
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
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public static function generateToken(): string
    {
        return 'ps_' . Str::random(48);
    }
}

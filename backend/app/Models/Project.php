<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'owner_id',
        'name',
        'status',
    ];

    /**
     * Auto-generate the public UUID on create. Existing rows are backfilled by migration. Route
     * model binding still resolves by numeric `id` (unchanged) — `uuid` is an additive, public
     * identifier used by platform-level (non-nested) APIs such as the Unified AI Workspace.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(ProjectSubscription::class);
    }

    public function moduleOverrides(): HasMany
    {
        return $this->hasMany(ProjectModuleOverride::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(ProjectInvite::class);
    }

    /**
     * Get the plan through the subscription (helper accessor)
     */
    public function getPlanAttribute(): ?Plan
    {
        return $this->subscription?->plan;
    }
}

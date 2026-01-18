<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'status',
    ];

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

    /**
     * Get the plan through the subscription (helper accessor)
     */
    public function getPlanAttribute(): ?Plan
    {
        return $this->subscription?->plan;
    }
}

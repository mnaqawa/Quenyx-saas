<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringProfile extends Model
{
    protected $fillable = [
        'workspace_id',
        'profile_key',
        'name',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitoringProfileCheck::class, 'profile_id')->orderBy('sort_order');
    }
}

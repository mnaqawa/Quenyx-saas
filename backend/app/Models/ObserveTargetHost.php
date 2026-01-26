<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObserveTargetHost extends Model
{
    use HasFactory;

    protected $table = 'observe_targets_hosts';

    protected $fillable = [
        'workspace_id',
        'name',
        'address',
        'check_command',
        'tags',
        'enabled',
    ];

    protected $casts = [
        'tags' => 'array',
        'enabled' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ObserveTargetService::class, 'host_id');
    }
}

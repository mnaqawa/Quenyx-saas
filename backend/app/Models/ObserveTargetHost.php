<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Observe target host (workspace-defined host to monitor).
 * Table: observe_targets_hosts (plural).
 */
class ObserveTargetHost extends Model
{
    use HasFactory;

    /** @var string */
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

    public function portScans(): HasMany
    {
        return $this->hasMany(HostPortScan::class, 'host_id');
    }
}

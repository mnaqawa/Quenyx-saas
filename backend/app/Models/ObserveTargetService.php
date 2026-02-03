<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Observe target service (workspace-defined service on a host).
 * Table: observe_targets_services (plural).
 */
class ObserveTargetService extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'observe_targets_services';

    protected $fillable = [
        'workspace_id',
        'host_id',
        'name',
        'service_key',
        'check_command',
        'check_args',
        'enabled',
    ];

    protected $casts = [
        'check_args' => 'array',
        'enabled' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(ObserveTargetHost::class, 'host_id');
    }
}

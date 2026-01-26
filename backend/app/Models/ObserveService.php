<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveService extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'engine_key',
        'engine_service_key',
        'host_name',
        'service_name',
        'state',
        'last_check_at',
        'duration_sec',
        'attempt',
        'output',
        'perfdata',
    ];

    protected $casts = [
        'last_check_at' => 'datetime',
        'duration_sec' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}

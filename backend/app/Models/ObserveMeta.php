<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveMeta extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'engine_key',
        'last_poll_at',
        'last_publish_at',
        'last_publish_success',
        'last_publish_error',
        'service_totals_json',
        'error',
    ];

    protected $casts = [
        'last_poll_at' => 'datetime',
        'last_publish_at' => 'datetime',
        'last_publish_success' => 'boolean',
        'service_totals_json' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}

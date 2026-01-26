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
        'service_totals_json',
        'error',
    ];

    protected $casts = [
        'last_poll_at' => 'datetime',
        'service_totals_json' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}

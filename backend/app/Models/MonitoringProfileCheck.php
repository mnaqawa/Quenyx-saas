<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringProfileCheck extends Model
{
    protected $fillable = [
        'profile_id',
        'service_key',
        'service_name',
        'check_args',
        'enabled',
        'sort_order',
    ];

    protected $casts = [
        'check_args' => 'array',
        'enabled' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MonitoringProfile::class, 'profile_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveTargetService extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'host_id',
        'name',
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

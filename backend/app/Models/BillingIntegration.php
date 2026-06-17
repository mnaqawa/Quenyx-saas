<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingIntegration extends Model
{
    public const PROVIDERS = ['manual', 'aws', 'azure', 'oracle_cloud', 'gcp', 'custom'];

    protected $fillable = [
        'workspace_id',
        'provider_type',
        'status',
        'config',
        'billing_contact',
        'connected_at',
    ];

    protected $casts = [
        'config' => 'array',
        'connected_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}

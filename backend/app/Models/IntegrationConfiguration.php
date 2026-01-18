<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationConfiguration extends Model
{
    protected $fillable = [
        'project_id',
        'integration_id',
        'github_pat',
        'slack_webhook_url',
        'primary_webhook_url',
        'backup_webhook_url',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }
}

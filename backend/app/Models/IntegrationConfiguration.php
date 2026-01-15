<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConfiguration extends Model
{
    protected $fillable = [
        'github_pat',
        'slack_webhook_url',
        'primary_webhook_url',
        'backup_webhook_url',
    ];
}

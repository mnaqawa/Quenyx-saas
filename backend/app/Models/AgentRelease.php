<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRelease extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_releases';

    protected $fillable = [
        'id',
        'version',
        'channel',
        'platform',
        'arch',
        'download_url',
        'checksum_sha256',
        'signature',
        'rollback_version',
        'mandatory',
        'is_latest',
        'published_at',
        'metadata',
    ];

    protected $casts = [
        'mandatory' => 'boolean',
        'is_latest' => 'boolean',
        'published_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(AgentUpdateAssignment::class, 'release_id');
    }
}

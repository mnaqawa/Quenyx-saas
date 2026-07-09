<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDiagnosticsBundle extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_diagnostics_bundles';

    protected $fillable = [
        'id',
        'agent_id',
        'workspace_id',
        'source',
        'summary',
        'bundle_json',
        'storage_path',
        'size_bytes',
        'generated_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'generated_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

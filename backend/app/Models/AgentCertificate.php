<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCertificate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_certificates';

    protected $fillable = [
        'id',
        'agent_id',
        'workspace_id',
        'status',
        'issuer',
        'fingerprint',
        'csr_pem',
        'certificate_pem',
        'issued_at',
        'expires_at',
        'rotation_due_at',
        'revoked_at',
        'revocation_reason',
        'metadata',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'rotation_due_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentGatewayCertificate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_gateway_certificates';

    protected $fillable = [
        'id',
        'gateway_id',
        'status',
        'issuer',
        'fingerprint',
        'certificate_pem',
        'trust_chain_pem',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];
}

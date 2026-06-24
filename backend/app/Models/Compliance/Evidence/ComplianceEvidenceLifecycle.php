<?php

namespace App\Models\Compliance\Evidence;

use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only lifecycle log of evidence state transitions. Each row records a single transition
 * (from_status → to_status) with the actor and an optional reason — the auditable history that
 * backs EvidenceLifecycleService. No transition is ever mutated or deleted.
 */
class ComplianceEvidenceLifecycle extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_evidence_lifecycle_events';

    protected $fillable = [
        'uuid',
        'evidence_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'metadata',
    ];

    protected $casts = [
        'from_status' => ComplianceEvidenceStatus::class,
        'to_status' => ComplianceEvidenceStatus::class,
        'metadata' => 'array',
    ];

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ComplianceEvidence::class, 'evidence_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

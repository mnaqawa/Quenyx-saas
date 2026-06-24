<?php

namespace App\Models\Compliance\Evidence;

use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant (workspace) evidence as a first-class QCIF object (QCIF Sprint 11).
 *
 * This is NOT corpus content and NOT a file: it is the metadata record of a piece of evidence
 * that can satisfy one or more requirements/controls. No file upload, blob storage, OCR, or AI
 * is involved — those are future sprints. All external exposure is UUID-only.
 */
class ComplianceEvidence extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_evidence';

    protected $fillable = [
        'uuid',
        'project_id',
        'evidence_type_id',
        'framework_id',
        'framework_release_id',
        'corpus_revision_id',
        'control_id',
        'requirement_id',
        'title',
        'description',
        'source',
        'source_reference',
        'status',
        'collected_at',
        'validated_at',
        'approved_at',
        'valid_from',
        'expires_at',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'status' => ComplianceEvidenceStatus::class,
        'collected_at' => 'datetime',
        'validated_at' => 'datetime',
        'approved_at' => 'datetime',
        'valid_from' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(ComplianceEvidenceType::class, 'evidence_type_id');
    }

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function corpusRevision(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusRevision::class, 'corpus_revision_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(ComplianceControl::class, 'control_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ComplianceEvidenceRelationship::class, 'evidence_id');
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ComplianceEvidenceLifecycle::class, 'evidence_id')->orderBy('id');
    }
}

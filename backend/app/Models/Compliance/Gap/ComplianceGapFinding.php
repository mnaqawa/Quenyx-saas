<?php

namespace App\Models\Compliance\Gap;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\Compliance\Gap\Concerns\ImmutableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable per-requirement gap finding within an assessment. Carries the full explainability
 * record: status, severity, the evaluation rule that produced it, the human reason, the evidence
 * considered vs ignored, and the exact revision/release evaluated. No black-box decisions.
 */
class ComplianceGapFinding extends Model
{
    use HasComplianceUuid;
    use ImmutableModel;

    protected $table = 'compliance_gap_findings';

    protected $fillable = [
        'uuid',
        'gap_assessment_id',
        'requirement_id',
        'requirement_uuid',
        'control_id',
        'control_uuid',
        'domain_id',
        'domain_uuid',
        'framework_release_id',
        'corpus_revision_id',
        'corpus_revision_uuid',
        'status',
        'severity',
        'evaluation_rule',
        'reason',
        'evidence_considered',
        'evidence_ignored',
        'metadata',
    ];

    protected $casts = [
        'status' => ComplianceGapStatus::class,
        'severity' => ComplianceGapSeverity::class,
        'evidence_considered' => 'array',
        'evidence_ignored' => 'array',
        'metadata' => 'array',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ComplianceGapAssessment::class, 'gap_assessment_id');
    }
}

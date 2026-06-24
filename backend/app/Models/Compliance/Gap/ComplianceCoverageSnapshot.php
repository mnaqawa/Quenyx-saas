<?php

namespace App\Models\Compliance\Gap;

use App\Enums\Compliance\Gap\ComplianceGapStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\Compliance\Gap\Concerns\ImmutableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable coverage aggregate for one scope (requirement | control | domain | framework |
 * workspace) within an assessment. Stores deterministic counts (totals by status) and the
 * rolled-up status — never a probabilistic score.
 */
class ComplianceCoverageSnapshot extends Model
{
    use HasComplianceUuid;
    use ImmutableModel;

    protected $table = 'compliance_coverage_snapshots';

    protected $fillable = [
        'uuid',
        'gap_assessment_id',
        'scope_type',
        'scope_id',
        'scope_uuid',
        'scope_code',
        'status',
        'totals',
        'framework_release_id',
        'corpus_revision_id',
        'metadata',
    ];

    protected $casts = [
        'status' => ComplianceGapStatus::class,
        'totals' => 'array',
        'metadata' => 'array',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ComplianceGapAssessment::class, 'gap_assessment_id');
    }
}

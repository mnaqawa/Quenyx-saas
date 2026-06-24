<?php

namespace App\Models\Compliance\Gap;

use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\Compliance\Gap\Concerns\ImmutableModel;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable, point-in-time gap assessment run for a workspace against a specific framework
 * release + corpus revision (QCIF Sprint 12). Every run is reproducible: it records exactly which
 * release/revision it evaluated. History is append-only.
 */
class ComplianceGapAssessment extends Model
{
    use HasComplianceUuid;
    use ImmutableModel;

    protected $table = 'compliance_gap_assessments';

    protected $fillable = [
        'uuid',
        'project_id',
        'framework_id',
        'framework_release_id',
        'corpus_revision_id',
        'assessed_at',
        'summary',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'summary' => 'array',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ComplianceGapFinding::class, 'gap_assessment_id');
    }

    public function coverageSnapshots(): HasMany
    {
        return $this->hasMany(ComplianceCoverageSnapshot::class, 'gap_assessment_id');
    }
}

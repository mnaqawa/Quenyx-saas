<?php

namespace App\Models\Compliance\Evidence;

use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a piece of evidence to a corpus entity it relates to (requirement | control | domain |
 * framework). One evidence may have many relationships — i.e. a single evidence can satisfy
 * MULTIPLE requirements. The corpus entity is referenced by both id and UUID so responses can
 * stay UUID-only while joins remain efficient.
 */
class ComplianceEvidenceRelationship extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_evidence_relationships';

    protected $fillable = [
        'uuid',
        'evidence_id',
        'entity_type',
        'entity_id',
        'entity_uuid',
        'relationship_type',
        'framework_release_id',
        'corpus_revision_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ComplianceEvidence::class, 'evidence_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function corpusRevision(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusRevision::class, 'corpus_revision_id');
    }
}

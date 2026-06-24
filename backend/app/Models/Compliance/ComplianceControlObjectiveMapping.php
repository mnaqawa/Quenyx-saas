<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\MappingConfidence;
use App\Enums\Compliance\ObjectiveMappingType;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceControlObjectiveMapping extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_control_objective_mappings';

    protected $fillable = [
        'uuid',
        'control_objective_id',
        'control_id',
        'framework_release_id',
        'corpus_revision_id',
        'source_document_id',
        'mapping_type',
        'confidence',
        'confidence_basis',
        'notes_en',
        'notes_ar',
        'status',
        'published_at',
        'source_reference',
        'source_page',
        'official_reference',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'status' => PublicationStatus::class,
        'mapping_type' => ObjectiveMappingType::class,
        'confidence_basis' => MappingConfidence::class,
    ];

    public function controlObjective(): BelongsTo
    {
        return $this->belongsTo(ComplianceControlObjective::class, 'control_objective_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(ComplianceControl::class, 'control_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function corpusRevision(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusRevision::class, 'corpus_revision_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ComplianceSourceDocument::class, 'source_document_id');
    }
}

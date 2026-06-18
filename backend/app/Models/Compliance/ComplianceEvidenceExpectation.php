<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceEvidenceExpectation extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_evidence_expectations';

    protected $fillable = [
        'uuid',
        'source_document_id',
        'requirement_id',
        'evidence_type_id',
        'code',
        'slug',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'is_required',
        'recency_days',
        'status',
        'published_at',
        'deprecated_at',
        'sort_order',
        'source_reference',
        'source_page',
        'official_reference',
        'tags',
        'metadata',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'metadata' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ComplianceSourceDocument::class, 'source_document_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(ComplianceEvidenceType::class, 'evidence_type_id');
    }
}

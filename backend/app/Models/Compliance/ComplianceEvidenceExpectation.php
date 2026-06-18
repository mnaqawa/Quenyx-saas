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
        'tags',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(ComplianceEvidenceType::class, 'evidence_type_id');
    }
}

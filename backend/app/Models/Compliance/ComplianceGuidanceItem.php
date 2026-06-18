<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\GuidanceType;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceGuidanceItem extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_guidance_items';

    protected $fillable = [
        'uuid',
        'source_document_id',
        'requirement_id',
        'code',
        'slug',
        'guidance_en',
        'guidance_ar',
        'guidance_type',
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
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'metadata' => 'array',
        'status' => PublicationStatus::class,
        'guidance_type' => GuidanceType::class,
    ];

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ComplianceSourceDocument::class, 'source_document_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }
}

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
        'tags',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'status' => PublicationStatus::class,
        'guidance_type' => GuidanceType::class,
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }
}

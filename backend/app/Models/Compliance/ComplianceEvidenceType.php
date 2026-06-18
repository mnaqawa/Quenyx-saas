<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceEvidenceType extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_evidence_types';

    protected $fillable = [
        'uuid',
        'key',
        'code',
        'slug',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
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
    ];

    public function expectations(): HasMany
    {
        return $this->hasMany(ComplianceEvidenceExpectation::class, 'evidence_type_id');
    }
}

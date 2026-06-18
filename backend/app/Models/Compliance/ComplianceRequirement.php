<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceRequirement extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_requirements';

    protected $fillable = [
        'uuid',
        'source_document_id',
        'control_id',
        'framework_release_id',
        'code',
        'display_code',
        'normalized_code',
        'slug',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'requirement_text_en',
        'requirement_text_ar',
        'status',
        'published_at',
        'deprecated_at',
        'sort_order',
        'source_reference',
        'source_page',
        'official_reference',
        'tags',
        'metadata',
        'superseded_by_requirement_id',
        'migration_reference',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'metadata' => 'array',
        'migration_reference' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ComplianceSourceDocument::class, 'source_document_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(ComplianceControl::class, 'control_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function guidanceItems(): HasMany
    {
        return $this->hasMany(ComplianceGuidanceItem::class, 'requirement_id')->orderBy('sort_order');
    }

    public function evidenceExpectations(): HasMany
    {
        return $this->hasMany(ComplianceEvidenceExpectation::class, 'requirement_id')->orderBy('sort_order');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_requirement_id');
    }
}

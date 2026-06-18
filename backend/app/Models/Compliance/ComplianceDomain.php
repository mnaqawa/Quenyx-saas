<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceDomain extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_domains';

    protected $fillable = [
        'uuid',
        'source_document_id',
        'framework_id',
        'framework_release_id',
        'parent_domain_id',
        'code',
        'display_code',
        'normalized_code',
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
        'source_page',
        'official_reference',
        'tags',
        'metadata',
        'superseded_by_domain_id',
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

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_domain_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_domain_id')->orderBy('sort_order');
    }

    public function controls(): HasMany
    {
        return $this->hasMany(ComplianceControl::class, 'domain_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_domain_id');
    }
}

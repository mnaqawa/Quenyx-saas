<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceFrameworkRelease extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_framework_releases';

    protected $fillable = [
        'uuid',
        'framework_id',
        'release_code',
        'version_code',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'effective_date',
        'published_at',
        'deprecated_at',
        'retired_at',
        'status',
        'superseded_by_release_id',
        'source_reference',
        'migration_reference',
        'metadata',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'retired_at' => 'datetime',
        'migration_reference' => 'array',
        'metadata' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_release_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ComplianceDomain::class, 'framework_release_id');
    }

    public function controls(): HasMany
    {
        return $this->hasMany(ComplianceControl::class, 'framework_release_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ComplianceRequirement::class, 'framework_release_id');
    }

    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(ComplianceSourceDocument::class, 'framework_release_id');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(ComplianceCorpusImportRun::class, 'framework_release_id');
    }

    public function stableRef(): string
    {
        return "{$this->framework?->key}:{$this->version_code}";
    }
}

<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceFramework extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_frameworks';

    protected $fillable = [
        'uuid',
        'key',
        'code',
        'slug',
        'version_code',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'authority',
        'authority_en',
        'authority_ar',
        'effective_from',
        'effective_to',
        'status',
        'published_at',
        'deprecated_at',
        'sort_order',
        'source_reference',
        'tags',
        'superseded_by_framework_id',
        'migration_reference',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'migration_reference' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function domains(): HasMany
    {
        return $this->hasMany(ComplianceDomain::class, 'framework_id');
    }

    public function controls(): HasMany
    {
        return $this->hasMany(ComplianceControl::class, 'framework_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_framework_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', PublicationStatus::Published);
    }

    public function stableRef(): string
    {
        return "{$this->key}:{$this->version_code}";
    }
}

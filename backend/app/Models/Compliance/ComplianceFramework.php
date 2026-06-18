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
        'authority_id',
        'key',
        'code',
        'slug',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'status',
        'sort_order',
        'tags',
        'metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function authority(): BelongsTo
    {
        return $this->belongsTo(ComplianceAuthority::class, 'authority_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(ComplianceFrameworkRelease::class, 'framework_id');
    }

    /** @deprecated Prefer release-scoped domains via ComplianceFrameworkRelease */
    public function domains(): HasMany
    {
        return $this->hasMany(ComplianceDomain::class, 'framework_id');
    }

    /** @deprecated Prefer release-scoped controls via ComplianceFrameworkRelease */
    public function controls(): HasMany
    {
        return $this->hasMany(ComplianceControl::class, 'framework_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', PublicationStatus::Published);
    }
}

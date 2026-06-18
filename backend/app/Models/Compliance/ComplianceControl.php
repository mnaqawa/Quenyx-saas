<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\ControlType;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceControl extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_controls';

    protected $fillable = [
        'uuid',
        'framework_id',
        'framework_release_id',
        'domain_id',
        'control_objective_id',
        'code',
        'slug',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'control_type',
        'status',
        'published_at',
        'deprecated_at',
        'sort_order',
        'source_reference',
        'tags',
        'superseded_by_control_id',
        'migration_reference',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'deprecated_at' => 'datetime',
        'tags' => 'array',
        'migration_reference' => 'array',
        'status' => PublicationStatus::class,
        'control_type' => ControlType::class,
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ComplianceDomain::class, 'domain_id');
    }

    public function controlObjective(): BelongsTo
    {
        return $this->belongsTo(ComplianceControlObjective::class, 'control_objective_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ComplianceRequirement::class, 'control_id')->orderBy('sort_order');
    }

    public function objectiveMappings(): HasMany
    {
        return $this->hasMany(ComplianceControlObjectiveMapping::class, 'control_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_control_id');
    }

    public function stableRef(): string
    {
        $key = $this->frameworkRelease?->framework?->key ?? $this->framework?->key ?? 'unknown';
        $version = $this->frameworkRelease?->version_code ?? 'unknown';

        return "{$key}:{$version}:{$this->code}";
    }
}

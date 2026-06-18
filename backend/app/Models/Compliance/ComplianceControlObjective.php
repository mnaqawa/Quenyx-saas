<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceControlObjective extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_control_objectives';

    protected $fillable = [
        'uuid',
        'code',
        'slug',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'category_en',
        'category_ar',
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

    public function controls(): HasMany
    {
        return $this->hasMany(ComplianceControl::class, 'control_objective_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ComplianceControlObjectiveMapping::class, 'control_objective_id');
    }
}

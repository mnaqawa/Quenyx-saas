<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\ObjectiveMappingType;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceControlObjectiveMapping extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_control_objective_mappings';

    protected $fillable = [
        'uuid',
        'control_objective_id',
        'control_id',
        'mapping_type',
        'confidence',
        'notes_en',
        'notes_ar',
        'status',
        'published_at',
        'source_reference',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'status' => PublicationStatus::class,
        'mapping_type' => ObjectiveMappingType::class,
    ];

    public function controlObjective(): BelongsTo
    {
        return $this->belongsTo(ComplianceControlObjective::class, 'control_objective_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(ComplianceControl::class, 'control_id');
    }
}

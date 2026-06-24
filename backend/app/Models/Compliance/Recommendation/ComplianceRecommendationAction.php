<?php

namespace App\Models\Compliance\Recommendation;

use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\Compliance\Gap\Concerns\ImmutableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single, normalized action item belonging to a recommendation (QCIF Sprint 13). Append-only.
 * Action items are also denormalized onto the parent recommendation's `action_items` JSON for
 * fast read; these rows exist for querying/assignment in a future remediation workflow. The
 * action text is a fixed, deterministic template — no LLM, no invented control text.
 */
class ComplianceRecommendationAction extends Model
{
    use HasComplianceUuid;
    use ImmutableModel;

    protected $table = 'compliance_recommendation_actions';

    protected $fillable = [
        'uuid',
        'recommendation_id',
        'action_key',
        'label_en',
        'label_ar',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(ComplianceRecommendation::class, 'recommendation_id');
    }
}

<?php

namespace App\Models\Incident;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 23 — a single chronological entry on an incident timeline (notes, status changes, linked
 * automation executions, alerts, assets, recommendations, evidence). Fully auditable.
 */
class IncidentTimelineEntry extends Model
{
    protected $table = 'incident_timeline_entries';

    protected $fillable = [
        'incident_id', 'project_id', 'created_by', 'at', 'type', 'category', 'description', 'metadata',
    ];

    protected $casts = [
        'at' => 'datetime',
        'metadata' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

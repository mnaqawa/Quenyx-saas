<?php

namespace App\Models\Incident;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Sprint 23 — a QynReact incident. A unified workspace that aggregates timeline, linked assets,
 * monitoring/alerts, recommendations, automation, evidence, resolution and postmortem. Reuses
 * Operations Intelligence and Asset Intelligence via the AI adapter registry (no module branching).
 */
class Incident extends Model
{
    protected $table = 'incidents';

    protected $fillable = [
        'uuid', 'project_id', 'opened_by', 'title', 'description', 'severity',
        'status', 'source', 'alert_uuid', 'asset_uuid', 'opened_at',
        'resolved_at', 'resolution', 'postmortem',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'postmortem' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->opened_at)) {
                $model->opened_at = now();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(IncidentTimelineEntry::class, 'incident_id')->orderBy('at');
    }
}

<?php

namespace App\Models\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 24 — a Service Desk ticket (QynSupport). Cross-module links (incident/asset) are stored as
 * deterministic UUID soft-references. AI suggestions (category/priority/impact/assignee/SLA/related)
 * are evidence-based and stored separately from operator-confirmed fields — never auto-applied.
 */
class Ticket extends Model
{
    protected $table = 'tickets';

    protected $fillable = [
        'uuid', 'project_id', 'requested_by', 'assigned_to', 'reference', 'subject', 'description',
        'category', 'priority', 'impact', 'status', 'source', 'incident_uuid', 'asset_uuid',
        'sla_due_at', 'resolved_at', 'ai_suggestions', 'metadata',
    ];

    protected $casts = [
        'ai_suggestions' => 'array',
        'metadata' => 'array',
        'sla_due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->reference)) {
                $model->reference = 'TCK-'.strtoupper(Str::random(6));
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

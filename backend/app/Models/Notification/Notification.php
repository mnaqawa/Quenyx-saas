<?php

namespace App\Models\Notification;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 24 — an intelligent notification (QynNotify). Deduplication (`dedup_key`) and correlation
 * (`correlation_id`) are deterministic and auditable; urgency, recipient selection, channel, and
 * escalation are computed from real evidence — no fake routing.
 */
class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'uuid', 'project_id', 'type', 'severity', 'title', 'body', 'source', 'dedup_key',
        'correlation_id', 'urgency_score', 'dedup_count', 'channel', 'status', 'recipients',
        'escalation', 'metadata', 'read_at',
    ];

    protected $casts = [
        'recipients' => 'array',
        'escalation' => 'array',
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}

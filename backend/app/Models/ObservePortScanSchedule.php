<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace-level nmap port scan schedule (auto black/white-box scans).
 */
class ObservePortScanSchedule extends Model
{
    protected $table = 'observe_port_scan_schedules';

    protected $fillable = [
        'workspace_id',
        'enabled',
        'interval_minutes',
        'target_mode',
        'ports',
        'ports_range',
        'protocol',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_minutes' => 'integer',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    /** Minimum allowed interval (minutes). */
    public static function minIntervalMinutes(): int
    {
        return 60;
    }

    public function scanOptions(): array
    {
        return [
            'ports' => $this->ports ?: 'top100',
            'ports_range' => (string) ($this->ports_range ?? ''),
            'protocol' => $this->protocol ?: 'tcp',
            'target_mode' => $this->target_mode ?: 'public',
        ];
    }

    public function computeNextRunAt(?\DateTimeInterface $from = null): \Illuminate\Support\Carbon
    {
        $base = $from ? \Illuminate\Support\Carbon::parse($from) : now();
        $minutes = max(self::minIntervalMinutes(), (int) $this->interval_minutes);

        return $base->copy()->addMinutes($minutes);
    }
}

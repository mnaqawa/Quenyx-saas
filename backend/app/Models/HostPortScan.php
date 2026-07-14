<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nmap port scan run for a target host.
 */
class HostPortScan extends Model
{
    protected $table = 'host_port_scans';

    protected $fillable = [
        'host_id',
        'status',
        'error_message',
        'scanned_at',
        'open_ports_count',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(ObserveTargetHost::class, 'host_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(HostPortScanResult::class, 'scan_id');
    }

    /**
     * Build API payload for a host: keep last completed open ports visible while a newer scan runs.
     *
     * @return array{host_id: int, host_name: string, address: string|null, public_ip: string|null, scan: array|null, ports: array<int, array>}
     */
    public static function presentForHost(ObserveTargetHost $host): array
    {
        $scans = $host->relationLoaded('portScans')
            ? $host->portScans
            : $host->portScans()->with('results')->orderByDesc('id')->limit(10)->get();

        $latest = $scans->first();
        $lastCompleted = $scans->first(fn ($s) => $s->status === 'completed');

        $portsSource = null;
        if ($latest && $latest->status === 'completed') {
            $portsSource = $latest;
        } elseif ($lastCompleted) {
            $portsSource = $lastCompleted;
        }

        $ports = [];
        if ($portsSource) {
            $results = $portsSource->relationLoaded('results')
                ? $portsSource->results
                : $portsSource->results()->get();
            $ports = $results->map(fn ($r) => [
                'port' => $r->port,
                'protocol' => $r->protocol,
                'state' => $r->state,
                'service' => $r->service,
                'version' => $r->version,
            ])->values()->all();
        }

        $displayScan = $latest;
        // Prefer completed metadata for open_ports_count/scanned_at when latest is in-flight.
        if ($latest && in_array($latest->status, ['pending', 'running', 'failed'], true) && $lastCompleted) {
            $displayScanMeta = [
                'id' => $latest->id,
                'status' => $latest->status,
                'scanned_at' => $lastCompleted->scanned_at?->toIso8601String(),
                'open_ports_count' => $lastCompleted->open_ports_count,
                'error_message' => $latest->error_message,
                'target_mode' => $latest->target_mode ?? $lastCompleted->target_mode ?? null,
                'scanned_address' => $latest->scanned_address ?? $lastCompleted->scanned_address ?? null,
                'previous_completed' => true,
            ];
        } elseif ($displayScan) {
            $displayScanMeta = [
                'id' => $displayScan->id,
                'status' => $displayScan->status,
                'scanned_at' => $displayScan->scanned_at?->toIso8601String(),
                'open_ports_count' => $displayScan->open_ports_count,
                'error_message' => $displayScan->error_message,
                'target_mode' => $displayScan->target_mode ?? null,
                'scanned_address' => $displayScan->scanned_address ?? null,
                'previous_completed' => false,
            ];
        } else {
            $displayScanMeta = null;
        }

        return [
            'host_id' => $host->id,
            'host_name' => $host->name,
            'address' => $host->address,
            'public_ip' => $host->public_ip ?? null,
            'scan' => $displayScanMeta,
            'ports' => $ports,
        ];
    }
}

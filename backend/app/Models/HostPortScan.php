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
        'target_mode',
        'scanned_address',
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
}

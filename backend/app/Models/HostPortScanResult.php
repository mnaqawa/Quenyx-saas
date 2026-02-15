<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single port result from an nmap scan.
 */
class HostPortScanResult extends Model
{
    protected $table = 'host_port_scan_results';

    protected $fillable = [
        'scan_id',
        'port',
        'protocol',
        'state',
        'service',
        'version',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(HostPortScan::class, 'scan_id');
    }
}

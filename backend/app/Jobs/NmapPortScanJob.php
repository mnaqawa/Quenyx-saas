<?php

namespace App\Jobs;

use App\Models\ObserveTargetHost;
use App\Services\NmapPortScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NmapPortScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array{ports?: string, ports_range?: string, protocol?: string, target_mode?: string}  $options
     */
    public function __construct(
        public int $hostId,
        public array $options = []
    ) {}

    public function handle(NmapPortScanService $service): void
    {
        $host = ObserveTargetHost::find($this->hostId);
        if (!$host) {
            Log::warning('NmapPortScanJob: host not found', ['host_id' => $this->hostId]);
            return;
        }

        $service->runScan($host, $this->options);
    }
}

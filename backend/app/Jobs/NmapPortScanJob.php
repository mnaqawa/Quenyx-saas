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

    public int $timeout = 150;

    public function __construct(
        public int $hostId
    ) {}

    public function handle(NmapPortScanService $service): void
    {
        $host = ObserveTargetHost::find($this->hostId);
        if (!$host) {
            Log::warning('NmapPortScanJob: host not found', ['host_id' => $this->hostId]);
            return;
        }

        $service->runScan($host);
    }
}

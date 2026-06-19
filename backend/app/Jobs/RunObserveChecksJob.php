<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Run native observe checks without blocking HTTP requests or piling up duplicates.
 */
class RunObserveChecksJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public ?int $workspaceId = null
    ) {}

    public function uniqueId(): string
    {
        return 'observe-run-checks-' . ($this->workspaceId ?? 'all');
    }

    public function uniqueFor(): int
    {
        return (int) config('observe.run_checks_unique_seconds', 120);
    }

    public function handle(): void
    {
        $params = [];
        if ($this->workspaceId !== null) {
            $params['--workspace_id'] = (string) $this->workspaceId;
        }

        try {
            Artisan::call('observe:run-checks', $params);
        } catch (\Throwable $e) {
            Log::warning('RunObserveChecksJob failed', [
                'workspace_id' => $this->workspaceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

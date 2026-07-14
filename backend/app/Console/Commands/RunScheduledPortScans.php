<?php

namespace App\Console\Commands;

use App\Jobs\NmapPortScanJob;
use App\Models\ObservePortScanSchedule;
use App\Models\ObserveTargetHost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RunScheduledPortScans extends Command
{
    protected $signature = 'observe:run-port-scans {--workspace= : Limit to one workspace id} {--force : Run even if not due}';

    protected $description = 'Queue nmap port scans for workspaces with an enabled auto-scan schedule';

    public function handle(): int
    {
        if (! Schema::hasTable('observe_port_scan_schedules')) {
            $this->warn('observe_port_scan_schedules table missing; skip.');

            return self::SUCCESS;
        }

        $query = ObservePortScanSchedule::query()->where('enabled', true);
        if ($this->option('workspace') !== null && $this->option('workspace') !== '') {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $force = (bool) $this->option('force');
        $now = now();
        $queued = 0;

        foreach ($query->get() as $schedule) {
            if (! $force) {
                if ($schedule->next_run_at && $schedule->next_run_at->gt($now)) {
                    continue;
                }
            }

            $hosts = ObserveTargetHost::where('workspace_id', $schedule->workspace_id)
                ->where('enabled', true)
                ->get();

            $options = $schedule->scanOptions();
            $hostQueued = 0;
            foreach ($hosts as $host) {
                $resolved = $host->resolveScanAddress($options['target_mode']);
                if (! $resolved['ok']) {
                    continue;
                }
                NmapPortScanJob::dispatch($host->id, $options);
                $hostQueued++;
            }

            $schedule->last_run_at = $now;
            $schedule->next_run_at = $schedule->computeNextRunAt($now);
            $schedule->save();

            $queued += $hostQueued;
            Log::info('observe:run-port-scans queued', [
                'workspace_id' => $schedule->workspace_id,
                'hosts' => $hostQueued,
                'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            ]);
            $this->info("Workspace {$schedule->workspace_id}: queued {$hostQueued} host(s).");
        }

        $this->info("Total hosts queued: {$queued}");

        return self::SUCCESS;
    }
}

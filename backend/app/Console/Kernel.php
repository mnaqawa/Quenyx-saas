<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Legacy alias command kept for compatibility (routes to native checks)
        // $schedule->command('observe:poll')->everyMinute()->withoutOverlapping(90);

        // QynSight engine: run native checks (HTTP/TCP/Ping/plugins); no Nagios required
        $schedule->command('observe:run-checks')
            ->everyMinute()
            ->withoutOverlapping(90)
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('observe:evaluate-alerts')
            ->everyMinute()
            ->withoutOverlapping(90)
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('observe:evaluate-alerts scheduler run failed');
            })
            ->appendOutputTo(storage_path('logs/scheduler.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
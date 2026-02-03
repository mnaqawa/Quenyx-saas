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
        // Poll Nagios (via gateway) when using Nagios daemon
        $schedule->command('observe:poll')
            ->everyMinute()
            ->withoutOverlapping(90);

        // ShieldObserve engine: run native checks (HTTP/TCP/Ping/plugins); no Nagios required
        $schedule->command('observe:run-checks')
            ->everyMinute()
            ->withoutOverlapping(90);
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
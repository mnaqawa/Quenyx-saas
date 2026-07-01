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

        // QynSight engine: run native checks; due services only (respects per-service interval)
        $schedule->command('observe:run-checks')
            ->everyTwoMinutes()
            ->when(fn () => array_key_exists('observe:run-checks', \Illuminate\Support\Facades\Artisan::all()))
            ->withoutOverlapping(120)
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('observe:evaluate-alerts')
            ->everyMinute()
            ->when(fn () => array_key_exists('observe:evaluate-alerts', \Illuminate\Support\Facades\Artisan::all()))
            ->withoutOverlapping(90)
            ->onFailure(function () {
                \App\Support\SafeLog::error('observe:evaluate-alerts scheduler run failed');
            })
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // GA HARDENING: prune expired/revoked personal access tokens daily (when Sanctum provides the command).
        $schedule->command('sanctum:prune-expired --hours=24')
            ->daily()
            ->when(fn () => array_key_exists('sanctum:prune-expired', \Illuminate\Support\Facades\Artisan::all()))
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
<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Log helpers that never throw when storage/logs is not writable.
 */
final class SafeLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function write(string $level, string $message, array $context = []): void
    {
        $context = array_merge([
            'logged_at' => now()->toIso8601String(),
            'logged_at_local' => now()->format('Y-m-d H:i:s T'),
        ], $context);

        try {
            Log::log($level, $message, $context);
        } catch (Throwable $e) {
            error_log("[{$level}] {$message} @ {$context['logged_at_local']} (log write failed: {$e->getMessage()})");
        }
    }
}

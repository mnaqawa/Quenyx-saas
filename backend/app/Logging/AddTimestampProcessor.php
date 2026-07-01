<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds explicit ISO timestamps to every log record context (in addition to Laravel's line prefix).
 */
final class AddTimestampProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        if (! isset($context['logged_at'])) {
            $context['logged_at'] = now()->toIso8601String();
        }
        if (! isset($context['logged_at_local'])) {
            $context['logged_at_local'] = now()->format('Y-m-d H:i:s T');
        }

        return $record->with(context: $context);
    }
}

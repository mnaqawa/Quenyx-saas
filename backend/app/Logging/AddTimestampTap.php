<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Registers AddTimestampProcessor on all Monolog handlers for the channel.
 */
final class AddTimestampTap
{
    public function __invoke(Logger $logger): void
    {
        $processor = new AddTimestampProcessor();
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor($processor);
        }
    }
}

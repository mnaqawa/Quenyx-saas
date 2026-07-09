<?php

namespace App\Constants;

final class AgentUpdateChannel
{
    public const STABLE = 'stable';

    public const BETA = 'beta';

    public const INTERNAL = 'internal';

    public const CANARY = 'canary';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::STABLE, self::BETA, self::INTERNAL, self::CANARY];
    }
}

<?php

namespace App\Constants;

/**
 * Policy sync status derived from heartbeat versions.
 */
final class AgentPolicyStatus
{
    public const UP_TO_DATE = 'up_to_date';

    public const POLICY_OUTDATED = 'policy_outdated';

    public const UPGRADE_AVAILABLE = 'upgrade_available';

    public const UNSUPPORTED_VERSION = 'unsupported_version';

    public const POLICY_SYNC_REQUIRED = 'policy_sync_required';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::UP_TO_DATE,
            self::POLICY_OUTDATED,
            self::UPGRADE_AVAILABLE,
            self::UNSUPPORTED_VERSION,
            self::POLICY_SYNC_REQUIRED,
        ];
    }
}

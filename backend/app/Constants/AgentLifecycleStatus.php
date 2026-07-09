<?php

namespace App\Constants;

/**
 * Platform Agent lifecycle states (enterprise fleet operations).
 */
final class AgentLifecycleStatus
{
    public const ONLINE = 'online';

    public const OFFLINE = 'offline';

    public const PENDING_ENROLLMENT = 'pending_enrollment';

    public const ENROLLMENT_FAILED = 'enrollment_failed';

    public const POLICY_SYNC_PENDING = 'policy_sync_pending';

    public const AGENT_UPDATING = 'agent_updating';

    public const UPGRADE_REQUIRED = 'upgrade_required';

    public const QUARANTINED = 'quarantined';

    public const MAINTENANCE = 'maintenance';

    public const DISCONNECTED = 'disconnected';

    public const DECOMMISSIONING = 'decommissioning';

    public const REVOKED = 'revoked';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ONLINE,
            self::OFFLINE,
            self::PENDING_ENROLLMENT,
            self::ENROLLMENT_FAILED,
            self::POLICY_SYNC_PENDING,
            self::AGENT_UPDATING,
            self::UPGRADE_REQUIRED,
            self::QUARANTINED,
            self::MAINTENANCE,
            self::DISCONNECTED,
            self::DECOMMISSIONING,
            self::REVOKED,
        ];
    }
}

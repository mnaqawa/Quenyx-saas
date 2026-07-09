<?php

namespace App\Constants;

/**
 * Observe host lifecycle states (administrative + derived health).
 */
final class HostLifecycleStatus
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const ONLINE = 'online';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const OFFLINE = 'offline';

    public const SUSPENDED = 'suspended';

    public const ARCHIVED = 'archived';

    public const AGENT_REMOVED = 'agent_removed';

    public const MONITORING_DISABLED = 'monitoring_disabled';

    public const DELETED = 'deleted';

    /**
     * Hosts in these states must not run service checks.
     *
     * @return array<int, string>
     */
    public static function monitoringBlocked(): array
    {
        return [
            self::SUSPENDED,
            self::ARCHIVED,
            self::AGENT_REMOVED,
            self::MONITORING_DISABLED,
            self::DELETED,
        ];
    }

    /**
     * Hosts counted as active in QynSight overview.
     *
     * @return array<int, string>
     */
    public static function countsAsActive(): array
    {
        return [
            self::ACTIVE,
            self::PENDING,
            self::ONLINE,
            self::WARNING,
            self::CRITICAL,
            self::OFFLINE,
        ];
    }

    /**
     * Default list filter (excludes archived/deleted).
     *
     * @return array<int, string>
     */
    public static function defaultListFilter(): array
    {
        return array_merge(self::countsAsActive(), [
            self::SUSPENDED,
            self::AGENT_REMOVED,
            self::MONITORING_DISABLED,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::PENDING,
            self::ONLINE,
            self::WARNING,
            self::CRITICAL,
            self::OFFLINE,
            self::SUSPENDED,
            self::ARCHIVED,
            self::AGENT_REMOVED,
            self::MONITORING_DISABLED,
            self::DELETED,
        ];
    }
}

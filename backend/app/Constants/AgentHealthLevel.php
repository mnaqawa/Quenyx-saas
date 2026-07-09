<?php

namespace App\Constants;

final class AgentHealthLevel
{
    public const HEALTHY = 'healthy';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    public static function fromScore(int $score, ?string $lifecycle = null): string
    {
        if ($lifecycle !== null && in_array($lifecycle, [
            AgentLifecycleStatus::QUARANTINED,
            AgentLifecycleStatus::REVOKED,
            AgentLifecycleStatus::DISCONNECTED,
            AgentLifecycleStatus::DECOMMISSIONING,
        ], true)) {
            return self::CRITICAL;
        }

        if ($lifecycle !== null && in_array($lifecycle, [
            AgentLifecycleStatus::MAINTENANCE,
            AgentLifecycleStatus::AGENT_UPDATING,
            AgentLifecycleStatus::UPGRADE_REQUIRED,
            AgentLifecycleStatus::POLICY_SYNC_PENDING,
        ], true)) {
            return self::WARNING;
        }

        if ($score >= 80) {
            return self::HEALTHY;
        }

        if ($score >= 50) {
            return self::WARNING;
        }

        return self::CRITICAL;
    }
}

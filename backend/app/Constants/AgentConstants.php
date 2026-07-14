<?php

namespace App\Constants;

/**
 * Quenyx Platform Agent (QPA) constants.
 *
 * QPA communicates outbound-only via Quenyx Agent Gateway (QAG) on HTTPS :9444.
 * Capabilities are modular plugins gated by subscription ∩ entitlements ∩ RBAC ∩ permissions.
 */
class AgentConstants
{
    /** @deprecated Use PROTOCOL_QAG */
    public const PROTOCOL_HTTP_API = 'qag';

    public const PROTOCOL_QAG = 'qag';

    /** @deprecated Legacy PSAP pull protocol — not used for enterprise deployments */
    public const PROTOCOL_PSAP = 'psap';

    public const PROTOCOL_SNMP = 'snmp';

    public const PROTOCOLS = [
        self::PROTOCOL_QAG => [
            'label' => 'Quenyx Agent Gateway (HTTPS Push)',
            'description' => 'Platform Agent pushes telemetry via outbound HTTPS to QAG. No inbound ports, SSH, or VPN required.',
            'port' => 9444,
            'direction' => 'push',
        ],
        'http_api' => [
            'label' => 'HTTP API (legacy alias)',
            'description' => 'Legacy alias for QAG push protocol.',
            'port' => 9444,
            'direction' => 'push',
            'deprecated' => true,
        ],
        self::PROTOCOL_PSAP => [
            'label' => 'Legacy PSAP (deprecated)',
            'description' => 'Deprecated inbound pull protocol. Use QAG instead.',
            'port' => 9444,
            'direction' => 'pull',
            'deprecated' => true,
        ],
        self::PROTOCOL_SNMP => [
            'label' => 'SNMP (optional)',
            'description' => 'SNMP polling. Requires SNMP agent on host.',
            'port' => 161,
            'direction' => 'pull',
        ],
    ];

    // Legacy permission keys (backward compatible)
    public const PERMISSION_SYSTEM_METRICS = 'system_metrics';

    public const PERMISSION_INVENTORY = 'inventory';

    public const PERMISSION_NETWORK = 'network';

    public const PERMISSION_PROCESSES = 'processes';

    public const PERMISSION_FILESYSTEM = 'filesystem';

    public const PERMISSION_AUTOMATION = 'automation';

    public const PERMISSION_COMPLIANCE = 'compliance';

    public const PERMISSIONS = [
        self::PERMISSION_SYSTEM_METRICS => [
            'label' => 'System metrics (CPU, memory, disk, load)',
            'required' => true,
            'module' => 'qynsight',
            'capabilities' => ['monitoring.telemetry', 'monitoring.service_checks'],
        ],
        self::PERMISSION_INVENTORY => [
            'label' => 'Hardware and software inventory',
            'required' => true,
            'module' => 'qynasset',
            'capabilities' => ['asset.inventory', 'asset.hardware', 'asset.software'],
        ],
        self::PERMISSION_NETWORK => [
            'label' => 'Network interfaces and topology',
            'required' => false,
            'module' => 'qynsight',
            'capabilities' => ['asset.network'],
        ],
        self::PERMISSION_PROCESSES => [
            'label' => 'Process list (service monitoring)',
            'required' => false,
            'module' => 'qynsight',
            'capabilities' => ['monitoring.service_checks'],
        ],
        self::PERMISSION_FILESYSTEM => [
            'label' => 'Filesystem usage and disk stats',
            'required' => true,
            'module' => 'qynsight',
            'capabilities' => ['monitoring.telemetry'],
        ],
        self::PERMISSION_AUTOMATION => [
            'label' => 'Automation runner (requires approval)',
            'required' => false,
            'module' => 'qynrun',
            'capabilities' => ['automation.runner', 'automation.execution'],
            'dangerous' => true,
            'default' => false,
        ],
        self::PERMISSION_COMPLIANCE => [
            'label' => 'Compliance evidence collection',
            'required' => false,
            'module' => 'qynshield',
            'capabilities' => ['compliance.evidence'],
            'default' => false,
        ],
    ];

    /** All known capability keys */
    public const CAPABILITIES = [
        'monitoring.telemetry' => ['module' => 'qynsight', 'label' => 'Monitoring telemetry'],
        'monitoring.service_checks' => ['module' => 'qynsight', 'label' => 'Service checks from agent'],
        'asset.inventory' => ['module' => 'qynasset', 'label' => 'Asset inventory'],
        'asset.hardware' => ['module' => 'qynasset', 'label' => 'Hardware inventory'],
        'asset.software' => ['module' => 'qynasset', 'label' => 'Software inventory'],
        'asset.network' => ['module' => 'qynsight', 'label' => 'Network interfaces'],
        'compliance.evidence' => ['module' => 'qynshield', 'label' => 'Compliance evidence', 'dangerous' => true],
        'automation.runner' => ['module' => 'qynrun', 'label' => 'Automation runner', 'dangerous' => true],
        'automation.execution' => ['module' => 'qynrun', 'label' => 'Automation execution', 'dangerous' => true],
        'incident.context' => ['module' => 'qynreact', 'label' => 'Incident context'],
        'notification.health' => ['module' => 'qynnotify', 'label' => 'Notification health'],
        'knowledge.context' => ['module' => 'qynknow', 'label' => 'Knowledge context'],
    ];

    /** Default permissions for new enrollment (safe defaults — no SSH, no automation, no compliance) */
    public const DEFAULT_PERMISSIONS = [
        self::PERMISSION_SYSTEM_METRICS,
        self::PERMISSION_INVENTORY,
        self::PERMISSION_FILESYSTEM,
        self::PERMISSION_NETWORK,
    ];

    public const CHECK_SOURCE_PULL = 'pull';

    public const CHECK_SOURCE_PLATFORM_AGENT = 'platform_agent';

    public const CHECK_SOURCE_SSH_ADVANCED = 'ssh_advanced';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_STALE = 'stale';

    public const STATUS_ERROR = 'error';

    public const AGENT_TYPE = 'quenyx_platform_agent';

    public const AGENT_VERSION = '1.0.1';
}

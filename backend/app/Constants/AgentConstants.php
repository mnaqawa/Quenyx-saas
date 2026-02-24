<?php

namespace App\Constants;

class AgentConstants
{
    public const PROTOCOL_HTTP_API = 'http_api';

    public const PROTOCOL_PSAP = 'psap';

    public const PROTOCOL_SNMP = 'snmp';

    public const PROTOCOLS = [
        self::PROTOCOL_PSAP => [
            'label' => 'Quenyx Agent Protocol (PSAP)',
            'description' => 'Custom TCP protocol for Quenyx. Platform connects to agent on port 9444. Requires inbound access from platform to agent.',
            'port' => 9444,
            'direction' => 'pull',
        ],
        self::PROTOCOL_HTTP_API => [
            'label' => 'HTTP API (Push)',
            'description' => 'Agent pushes metrics and inventory to the platform. Works across firewalls; only outbound HTTPS required.',
            'port' => null,
            'direction' => 'push',
        ],
        self::PROTOCOL_SNMP => [
            'label' => 'SNMP',
            'description' => 'Platform polls agent via SNMP (UDP 161). Requires SNMP agent on the host.',
            'port' => 161,
            'direction' => 'pull',
        ],
    ];

    public const PERMISSION_SYSTEM_METRICS = 'system_metrics';

    public const PERMISSION_INVENTORY = 'inventory';

    public const PERMISSION_NETWORK = 'network';

    public const PERMISSION_PROCESSES = 'processes';

    public const PERMISSION_FILESYSTEM = 'filesystem';

    public const PERMISSIONS = [
        self::PERMISSION_SYSTEM_METRICS => [
            'label' => 'System metrics (CPU, memory, disk, load)',
            'required' => true,
        ],
        self::PERMISSION_INVENTORY => [
            'label' => 'Hardware and software inventory',
            'required' => true,
        ],
        self::PERMISSION_NETWORK => [
            'label' => 'Network interfaces and connections',
            'required' => false,
        ],
        self::PERMISSION_PROCESSES => [
            'label' => 'Process list (for service monitoring)',
            'required' => false,
        ],
        self::PERMISSION_FILESYSTEM => [
            'label' => 'Filesystem usage and disk stats',
            'required' => true,
        ],
    ];

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_STALE = 'stale';

    public const STATUS_ERROR = 'error';
}

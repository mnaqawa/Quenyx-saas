<?php

return [
    'source_path' => env('AGENT_SOURCE_PATH', base_path('../agent')),
    'go_binary' => env('AGENT_GO_BINARY', 'go'),
    'build_on_demand' => env('AGENT_BUILD_ON_DEMAND', true),

    /*
    | Quenyx Agent Gateway (QAG) — agents connect here, never to Laravel directly.
    | Default: https://cloud.quenyx.com:9444
    */
    'gateway' => [
        'protocol' => env('AGENT_GATEWAY_PROTOCOL', 'https'),
        'host' => env('AGENT_GATEWAY_HOST', 'cloud.quenyx.com'),
        'port' => (int) env('AGENT_GATEWAY_PORT', 9444),
    ],

    /*
    | Stale agent threshold (minutes without heartbeat).
    */
    'stale_after_minutes' => (int) env('AGENT_STALE_AFTER_MINUTES', 15),

    /*
    | Block direct agent API access when QAG is required (production hardening).
    */
    'require_gateway' => env('AGENT_REQUIRE_GATEWAY', false),

    'gateway_region' => env('AGENT_GATEWAY_REGION', 'default'),
    'gateway_version' => env('AGENT_GATEWAY_VERSION', '1.0.0'),
    'gateway_capacity' => (int) env('AGENT_GATEWAY_CAPACITY', 5000),

  /*
    | Policy versioning — agents report these on heartbeat; platform compares.
    */
    'policy' => [
        'version' => env('AGENT_POLICY_VERSION', '1.0.0'),
        'platform_version' => env('PLATFORM_VERSION', '1.0.0'),
        'latest_agent_version' => env('AGENT_LATEST_VERSION', '1.0.0'),
        'supported_agent_versions' => ['1.0.0', '1.1.0'],
    ],

    'configuration' => [
        'default_version' => env('AGENT_CONFIG_VERSION', '1.0.0'),
        'defaults' => [
            'heartbeat_interval_seconds' => (int) env('AGENT_HEARTBEAT_INTERVAL', 300),
            'telemetry_interval_seconds' => (int) env('AGENT_TELEMETRY_INTERVAL', 60),
            'inventory_interval_seconds' => (int) env('AGENT_INVENTORY_INTERVAL', 21600),
            'bandwidth_limit_kbps' => (int) env('AGENT_BANDWIDTH_LIMIT_KBPS', 0),
            'compression_enabled' => (bool) env('AGENT_COMPRESSION_ENABLED', true),
            'retry_max_attempts' => (int) env('AGENT_RETRY_MAX_ATTEMPTS', 5),
            'retry_backoff_seconds' => (int) env('AGENT_RETRY_BACKOFF_SECONDS', 30),
            'logging_level' => env('AGENT_LOGGING_LEVEL', 'info'),
            'retention_days' => (int) env('AGENT_RETENTION_DAYS', 7),
            'upload_limit_mb' => (int) env('AGENT_UPLOAD_LIMIT_MB', 50),
        ],
    ],

    'updates' => [
        'channels' => ['stable', 'beta', 'internal', 'canary'],
    ],

    'health' => [
        'weights' => [
            'heartbeat_freshness' => 20,
            'policy_sync' => 15,
            'plugin_health' => 10,
            'resource_utilization' => 10,
            'gateway_connectivity' => 10,
            'version_currency' => 10,
            'update_status' => 5,
            'certificate_status' => 5,
            'capability_errors' => 10,
            'recent_failures' => 15,
        ],
    ],

    'certificates' => [
        'mtls_enabled' => (bool) env('AGENT_MTLS_ENABLED', false),
        'issuer' => env('AGENT_CERT_ISSUER', 'Quenyx Platform CA'),
        'rotation_days_before_expiry' => (int) env('AGENT_CERT_ROTATION_DAYS', 30),
    ],

    'offline_queue' => [
        'retention_days' => (int) env('AGENT_QUEUE_RETENTION_DAYS', 7),
        'max_disk_mb' => (int) env('AGENT_QUEUE_MAX_DISK_MB', 256),
        'max_events' => (int) env('AGENT_QUEUE_MAX_EVENTS', 10000),
    ],
];

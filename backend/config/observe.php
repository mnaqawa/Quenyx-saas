<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Custom command allowlist (native QynSight)
    |--------------------------------------------------------------------------
    | When service_key=custom, the command name MUST be in this list.
    | Empty = no custom commands allowed. Add engine-specific commands as needed.
    */
    'custom_command_allowlist' => array_filter(
        array_map(
            'trim',
            explode(',', env('OBSERVE_CUSTOM_COMMAND_ALLOWLIST', 'check_dns,check_ntp,check_ssh'))
        )
    ),

    'stale_threshold_seconds' => (int) env('OBSERVE_STALE_THRESHOLD_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Performance metrics history retention (days)
    |--------------------------------------------------------------------------
    | Per-check CPU/memory/disk/network samples are stored in observe_metrics_history
    | to power Performance Analytics time ranges (1h..30d). Older rows are pruned.
    */
    'metrics_retention_days' => (int) env('OBSERVE_METRICS_RETENTION_DAYS', 31),

    'publish_lock_seconds' => (int) env('OBSERVE_PUBLISH_LOCK_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Observe plugins (native engine)
    |--------------------------------------------------------------------------
    | Directory containing plugin scripts (PHP, Perl, shell). Scripts receive
    | env vars from the engine and must exit 0=OK, 1=Warning, 2=Critical, 3=Unknown.
    | Relative to storage_path() or absolute. Created if missing.
    */
    'plugins_dir' => env('OBSERVE_PLUGINS_DIR', 'app/observe_plugins'),
    'plugin_timeout_seconds' => (int) env('OBSERVE_PLUGIN_TIMEOUT_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Built-in check timeouts (HTTP, TCP, Ping)
    |--------------------------------------------------------------------------
    */
    'http_timeout_seconds' => (float) env('OBSERVE_HTTP_TIMEOUT_SECONDS', 10),
    'connect_timeout_seconds' => (float) env('OBSERVE_CONNECT_TIMEOUT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Check scheduling (native engine)
    |--------------------------------------------------------------------------
    | Intervals are stored in seconds. UI shows minutes and converts on save.
    */
    'default_check_interval_seconds' => (int) env('OBSERVE_DEFAULT_CHECK_INTERVAL_SECONDS', 300),
    'default_retry_interval_seconds' => (int) env('OBSERVE_DEFAULT_RETRY_INTERVAL_SECONDS', 60),
    'min_check_interval_seconds' => (int) env('OBSERVE_MIN_CHECK_INTERVAL_SECONDS', 60),
    'run_checks_unique_seconds' => (int) env('OBSERVE_RUN_CHECKS_UNIQUE_SECONDS', 120),
    'max_checks_per_run' => (int) env('OBSERVE_MAX_CHECKS_PER_RUN', 0),

    /*
    |--------------------------------------------------------------------------
    | Nmap port scan (Infrastructure Map)
    |--------------------------------------------------------------------------
    | Ports: --top-ports 100 (fast) or -p 1-1024 (common) or -p- (all 65535, slow).
    | Timeout: max seconds for nmap process.
    */
    'nmap_ports' => env('OBSERVE_NMAP_PORTS', '--top-ports 100'),
    'nmap_timeout_seconds' => (int) env('OBSERVE_NMAP_TIMEOUT_SECONDS', 120),
];

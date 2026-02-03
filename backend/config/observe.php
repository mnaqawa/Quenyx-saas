<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Custom command allowlist (Nagios)
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
];

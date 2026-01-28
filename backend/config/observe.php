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
];

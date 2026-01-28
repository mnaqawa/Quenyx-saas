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
];

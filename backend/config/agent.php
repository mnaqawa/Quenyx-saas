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
];

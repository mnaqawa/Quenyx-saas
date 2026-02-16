<?php

return [
    /*
    | Agent source directory (Go module). Used for on-demand build when binary is missing.
    | Default: directory "agent" next to the backend (base_path('../agent')).
    | Set AGENT_SOURCE_PATH in .env to override (absolute path).
    */
    'source_path' => env('AGENT_SOURCE_PATH', base_path('../agent')),

    /*
    | Path to the Go binary used for on-demand build. PHP-FPM often has a minimal PATH, so set this
    | to the full path (e.g. /usr/bin/go) if "go" is not found when the web server runs.
    */
    'go_binary' => env('AGENT_GO_BINARY', 'go'),

    /*
    | Whether to build the agent on-demand when a download is requested and the binary is missing.
    | Requires Go to be installed on the server. Set to false to disable and only serve pre-built binaries.
    */
    'build_on_demand' => env('AGENT_BUILD_ON_DEMAND', true),
];

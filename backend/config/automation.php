<?php

/*
|--------------------------------------------------------------------------
| Sprint 23 — Automation Platform configuration
|--------------------------------------------------------------------------
| The shared Automation Platform is SAFE BY DEFAULT. The master switch `live_execution` is OFF: every
| execution produces a deterministic dry-run plan and performs no side effect. Even when live execution
| is enabled, each live action still requires explicit human approval, HTTP targets must be allowlisted,
| and shell/script runners must be individually enabled. Nothing destructive ever happens automatically.
*/

return [
    // Master safety switch. When false (default), NO adapter performs a live side effect anywhere.
    'live_execution' => (bool) env('AUTOMATION_LIVE_EXECUTION', false),

    'defaults' => [
        'timeout_seconds' => (int) env('AUTOMATION_TIMEOUT_SECONDS', 60),
        'max_retries' => (int) env('AUTOMATION_MAX_RETRIES', 0),
    ],

    'http' => [
        // Hosts permitted for live REST/Webhook calls (comma-separated). Empty => no live HTTP.
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTOMATION_HTTP_ALLOWED_HOSTS', ''))))),
    ],

    'script' => [
        'runner_enabled' => (bool) env('AUTOMATION_SCRIPT_RUNNER_ENABLED', false),
    ],
    'ssh' => [
        'runner_enabled' => (bool) env('AUTOMATION_SSH_RUNNER_ENABLED', false),
    ],
    'powershell' => [
        'runner_enabled' => (bool) env('AUTOMATION_POWERSHELL_RUNNER_ENABLED', false),
    ],

    /*
    | The default Action Registry catalog. Actions bind a stable key to an execution adapter + schema and
    | declare whether they are destructive (always require approval) and rollbackable. Extend at runtime
    | via ActionRegistry::register() — the engine has NO hardcoded action list.
    */
    'actions' => [
        [
            'key' => 'http_request',
            'label' => 'HTTP request',
            'description' => 'Call a REST endpoint (method, headers, JSON body) against an allowlisted host.',
            'adapter_key' => 'rest',
            'category' => 'integration',
            'destructive' => false,
            'supports_rollback' => false,
        ],
        [
            'key' => 'send_webhook',
            'label' => 'Send webhook',
            'description' => 'POST a JSON payload to an allowlisted webhook (ChatOps, ticketing).',
            'adapter_key' => 'webhook',
            'category' => 'notification',
            'destructive' => false,
            'supports_rollback' => false,
        ],
        [
            'key' => 'run_diagnostic_script',
            'label' => 'Run diagnostic script',
            'description' => 'Run a read-only diagnostic script through a sandboxed runner.',
            'adapter_key' => 'script',
            'category' => 'diagnostics',
            'destructive' => false,
            'supports_rollback' => false,
        ],
        [
            'key' => 'restart_service_linux',
            'label' => 'Restart service (Linux)',
            'description' => 'Restart a systemd service on a remote Linux host over SSH.',
            'adapter_key' => 'ssh',
            'category' => 'remediation',
            'destructive' => true,
            'supports_rollback' => true,
        ],
        [
            'key' => 'restart_service_windows',
            'label' => 'Restart service (Windows)',
            'description' => 'Restart a Windows service via PowerShell.',
            'adapter_key' => 'powershell',
            'category' => 'remediation',
            'destructive' => true,
            'supports_rollback' => false,
        ],
        [
            'key' => 'clear_disk_space',
            'label' => 'Clear disk space',
            'description' => 'Run a guarded cleanup command on a remote host over SSH.',
            'adapter_key' => 'ssh',
            'category' => 'remediation',
            'destructive' => true,
            'supports_rollback' => false,
        ],
        [
            'key' => 'scale_deployment',
            'label' => 'Scale deployment',
            'description' => 'Scale a Kubernetes deployment (requires a provisioned runner).',
            'adapter_key' => 'kubernetes',
            'category' => 'remediation',
            'destructive' => true,
            'supports_rollback' => true,
        ],
        [
            'key' => 'restart_container',
            'label' => 'Restart container',
            'description' => 'Restart a Docker container (requires a provisioned runner).',
            'adapter_key' => 'docker',
            'category' => 'remediation',
            'destructive' => true,
            'supports_rollback' => false,
        ],
    ],

    /*
    | Planned (registry-discoverable) execution surfaces whose runners are not provisioned by default.
    | Each is a first-class adapter; provisioning a real runner later swaps the implementation with no
    | engine/API change.
    */
    'planned_adapters' => [
        ['key' => 'docker', 'name' => 'Docker', 'category' => 'container', 'description' => 'Run container lifecycle operations via a Docker runner.'],
        ['key' => 'kubernetes', 'name' => 'Kubernetes', 'category' => 'container', 'description' => 'Run kubectl operations (scale, rollout, restart) via a Kubernetes runner.'],
        ['key' => 'oci', 'name' => 'OCI', 'category' => 'cloud', 'description' => 'Run Oracle Cloud Infrastructure operations via an OCI runner.'],
        ['key' => 'aws', 'name' => 'AWS', 'category' => 'cloud', 'description' => 'Run AWS operations (EC2, ECS, Lambda, ...) via an AWS runner.'],
        ['key' => 'azure', 'name' => 'Azure', 'category' => 'cloud', 'description' => 'Run Azure operations via an Azure runner.'],
        ['key' => 'gcp', 'name' => 'GCP', 'category' => 'cloud', 'description' => 'Run Google Cloud operations via a GCP runner.'],
    ],
];

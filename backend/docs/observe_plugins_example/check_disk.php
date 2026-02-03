#!/usr/bin/env php
<?php
/**
 * Example observe plugin (PHP). Copy to storage/app/observe_plugins/ (or OBSERVE_PLUGINS_DIR).
 * Receives env: OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS (JSON), OBSERVE_SERVICE_NAME, OBSERVE_WORKSPACE_ID, OBSERVE_HOST_NAME.
 * Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown. Output: one line; optional " | perfdata".
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$mount = $args['mount'] ?? '/';
$host = getenv('OBSERVE_HOST_ADDRESS') ?: '127.0.0.1';

// Example: local disk check (simplified; real plugin would run df or SSH to $host)
if ($host !== '127.0.0.1' && $host !== 'localhost') {
    echo "UNKNOWN - Remote disk check not implemented (host={$host})\n";
    exit(3);
}
$freePct = 25; // Replace with: shell_exec("df -P " . escapeshellarg($mount) . " | tail -1 | awk '{print \$5}'"); then 100 - used
if ($freePct >= 20) {
    echo "DISK OK - {$freePct}% free on {$mount}\n";
    exit(0);
}
if ($freePct >= 10) {
    echo "DISK WARNING - {$freePct}% free on {$mount}\n";
    exit(1);
}
echo "DISK CRITICAL - {$freePct}% free on {$mount}\n";
exit(2);

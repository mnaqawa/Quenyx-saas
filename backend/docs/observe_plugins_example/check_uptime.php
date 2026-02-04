#!/usr/bin/env php
<?php
/**
 * Observe plugin: System uptime. Install with: php artisan observe:install-plugins
 * Host from UI (OBSERVE_HOST_ADDRESS). Reports uptime in human-readable form. No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

// Local = same machine (derived at runtime; no hardcoded IPs)
$localIdentifiers = [];
if (function_exists('gethostname')) {
    $localIdentifiers[] = strtolower(trim((string) gethostname()));
}
$localIdentifiers[] = 'localhost';
if (function_exists('gethostbyname')) {
    $lb = gethostbyname('localhost');
    if ($lb !== '' && $lb !== 'localhost') {
        $localIdentifiers[] = $lb;
    }
}
$localIdentifiers[] = '::1';
$isLocal = in_array(strtolower($host), $localIdentifiers, true);

if ($isLocal) {
    $uptime = is_readable('/proc/uptime') ? file_get_contents('/proc/uptime') : '';
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " 'cat /proc/uptime 2>/dev/null'";
    $uptime = (string) @shell_exec($cmd);
}

$seconds = 0;
if (preg_match('/^([\d.]+)/', trim($uptime), $m)) {
    $seconds = (float) $m[1];
}
if ($seconds <= 0) {
    echo "UNKNOWN - Could not read uptime for {$host}\n";
    exit(3);
}

$days = (int) floor($seconds / 86400);
$hours = (int) floor(($seconds % 86400) / 3600);
$minutes = (int) floor(($seconds % 3600) / 60);
$parts = [];
if ($days > 0) {
    $parts[] = $days . ' day' . ($days !== 1 ? 's' : '');
}
$parts[] = $hours . ' hour' . ($hours !== 1 ? 's' : '');
$parts[] = $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
$str = implode(', ', $parts);
$perf = sprintf('uptime=%.0fs', $seconds);
echo "UPTIME OK - {$str} ({$host}) | {$perf}\n";
exit(0);

#!/usr/bin/env php
<?php
/**
 * Observe plugin: Current users. Install with: php artisan observe:install-plugins
 * Host and thresholds from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warn = isset($args['warn']) ? (int) $args['warn'] : 20;
$crit = isset($args['crit']) ? (int) $args['crit'] : 50;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

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
    $count = 0;
    if (is_readable('/var/run/utmp')) {
        $count = @shell_exec('who 2>/dev/null | wc -l');
    }
    if ($count === null || $count === '') {
        $out = [];
        @exec('who 2>/dev/null', $out);
        $count = count($out);
    } else {
        $count = (int) trim($count);
    }
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " \"who 2>/dev/null | wc -l\"";
    $count = (int) trim((string) @shell_exec($cmd));
}

$perf = sprintf('users=%d;%d;%d;0', $count, $warn, $crit);
if ($crit > 0 && $count >= $crit) {
    echo "USERS CRITICAL - {$count} logged in ({$host}) | {$perf}\n";
    exit(2);
}
if ($warn > 0 && $count >= $warn) {
    echo "USERS WARNING - {$count} logged in ({$host}) | {$perf}\n";
    exit(1);
}
echo "USERS OK - {$count} logged in ({$host}) | {$perf}\n";
exit(0);

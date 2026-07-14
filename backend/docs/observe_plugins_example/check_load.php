#!/usr/bin/env php
<?php
/**
 * Observe plugin: Load average. Copy to storage/app/observe_plugins/check_load.php.
 * Host and args from engine (UI: host under target; warn/crit thresholds from service config).
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
require_once __DIR__ . '/_observe_local.php';
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warn1 = isset($args['warn_1']) ? (float) $args['warn_1'] : 4.0;
$crit1 = isset($args['crit_1']) ? (float) $args['crit_1'] : 6.0;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$isLocal = observe_is_local_host($host);
$loadRaw = '';
$parsed = false;
$load1 = $load5 = $load15 = 0.0;

if ($isLocal) {
    if (function_exists('sys_getloadavg')) {
        $avg = @sys_getloadavg();
        if (is_array($avg) && count($avg) >= 3) {
            $load1 = (float) $avg[0];
            $load5 = (float) $avg[1];
            $load15 = (float) $avg[2];
            $parsed = true;
        }
    }
    if (! $parsed && is_readable('/proc/loadavg')) {
        $loadRaw = (string) file_get_contents('/proc/loadavg');
    }
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o BatchMode=yes "
        .escapeshellarg($host)
        ." 'cat /proc/loadavg 2>/dev/null'";
    $loadRaw = (string) @shell_exec($cmd);
}

if (! $parsed && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', trim($loadRaw), $m)) {
    $load1 = (float) $m[1];
    $load5 = (float) $m[2];
    $load15 = (float) $m[3];
    $parsed = true;
}

// Genuine idle hosts report 0.00 load — that is OK, not UNKNOWN.
if (! $parsed) {
    echo "UNKNOWN - Could not read load average for {$host}\n";
    exit(3);
}

$perf = sprintf('load1=%.2f;%.2f;%.2f load5=%.2f load15=%.2f', $load1, $warn1, $crit1, $load5, $load15);
if ($load1 >= $crit1) {
    echo "LOAD CRITICAL - load1={$load1} ({$host}) | {$perf}\n";
    exit(2);
}
if ($load1 >= $warn1) {
    echo "LOAD WARNING - load1={$load1} ({$host}) | {$perf}\n";
    exit(1);
}
echo "LOAD OK - load1={$load1}, load5={$load5}, load15={$load15} ({$host}) | {$perf}\n";
exit(0);

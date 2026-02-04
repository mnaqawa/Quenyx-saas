#!/usr/bin/env php
<?php
/**
 * Observe plugin: Load average. Copy to storage/app/observe_plugins/check_load.php.
 * Host and args from engine (UI: host under target; warn/crit thresholds from service config).
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warn1 = isset($args['warn_1']) ? (float) $args['warn_1'] : 4.0;
$crit1 = isset($args['crit_1']) ? (float) $args['crit_1'] : 6.0;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$isLocal = in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)
    || (function_exists('gethostname') && strtolower(trim($host)) === strtolower(trim((string) gethostname())));

if ($isLocal) {
    $load = is_readable('/proc/loadavg') ? file_get_contents('/proc/loadavg') : '';
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " 'cat /proc/loadavg 2>/dev/null'";
    $load = (string) @shell_exec($cmd);
}

$load1 = $load5 = $load15 = 0.0;
if (preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', trim($load), $m)) {
    $load1 = (float) $m[1];
    $load5 = (float) $m[2];
    $load15 = (float) $m[3];
}
if ($load1 === 0.0 && $load5 === 0.0 && $load15 === 0.0) {
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

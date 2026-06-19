#!/usr/bin/env php
<?php
/**
 * Observe plugin: Memory (RAM). Copy to storage/app/observe_plugins/check_memory.php.
 * Host and all args from engine (UI: host under target; warn_pct/crit_pct/type from service config).
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
require_once __DIR__ . '/_observe_local.php';
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warnPct = isset($args['warn_pct']) ? (float) $args['warn_pct'] : 85;
$critPct = isset($args['crit_pct']) ? (float) $args['crit_pct'] : 95;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$isLocal = observe_is_local_host($host);

if ($isLocal) {
    $mem = is_readable('/proc/meminfo') ? file_get_contents('/proc/meminfo') : '';
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " 'cat /proc/meminfo 2>/dev/null'";
    $mem = (string) @shell_exec($cmd);
}

$usedPct = null;
if (preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemAvailable:\s+(\d+)/', $mem, $a)) {
    $total = (int) $t[1];
    $avail = (int) $a[1];
    $usedPct = $total > 0 ? round((($total - $avail) / $total) * 100, 1) : 0;
} elseif (preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemFree:\s+(\d+)/', $mem, $f)) {
    $total = (int) $t[1];
    $free = (int) $f[1];
    $usedPct = $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0;
}

if ($usedPct === null) {
    echo "UNKNOWN - Could not read memory info for {$host}\n";
    exit(3);
}

$perf = sprintf('used_pct=%.1f%%;%.0f;%.0f;0;100', $usedPct, $warnPct, $critPct);
if ($usedPct >= $critPct) {
    echo "MEMORY CRITICAL - {$usedPct}% used ({$host}) | {$perf}\n";
    exit(2);
}
if ($usedPct >= $warnPct) {
    echo "MEMORY WARNING - {$usedPct}% used ({$host}) | {$perf}\n";
    exit(1);
}
echo "MEMORY OK - {$usedPct}% used ({$host}) | {$perf}\n";
exit(0);

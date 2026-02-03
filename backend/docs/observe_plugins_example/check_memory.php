#!/usr/bin/env php
<?php
/**
 * Example observe plugin: Memory (RAM). Copy to storage/app/observe_plugins/check_memory.php.
 * Env: OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 * Args: warn_pct, crit_pct (optional), type (e.g. used).
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warnPct = (float) ($args['warn_pct'] ?? 85);
$critPct = (float) ($args['crit_pct'] ?? 95);
$host = getenv('OBSERVE_HOST_ADDRESS') ?: '127.0.0.1';

if ($host !== '127.0.0.1' && $host !== 'localhost') {
    echo "UNKNOWN - Remote memory check not implemented (use NRPE on target)\n";
    exit(3);
}

// Linux: parse /proc/meminfo for MemTotal, MemAvailable or MemFree
$usedPct = null;
if (is_readable('/proc/meminfo')) {
    $mem = file_get_contents('/proc/meminfo');
    if (preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemAvailable:\s+(\d+)/', $mem, $a)) {
        $total = (int) $t[1];
        $avail = (int) $a[1];
        $usedPct = $total > 0 ? round((($total - $avail) / $total) * 100, 1) : 0;
    } elseif (preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemFree:\s+(\d+)/', $mem, $f)) {
        $total = (int) $t[1];
        $free = (int) $f[1];
        $usedPct = $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0;
    }
}

if ($usedPct === null) {
    echo "UNKNOWN - Could not read memory info\n";
    exit(3);
}

$perf = sprintf('used_pct=%.1f%%;%.0f;%.0f;0;100', $usedPct, $warnPct, $critPct);
if ($usedPct >= $critPct) {
    echo "MEMORY CRITICAL - {$usedPct}% used | {$perf}\n";
    exit(2);
}
if ($usedPct >= $warnPct) {
    echo "MEMORY WARNING - {$usedPct}% used | {$perf}\n";
    exit(1);
}
echo "MEMORY OK - {$usedPct}% used | {$perf}\n";
exit(0);

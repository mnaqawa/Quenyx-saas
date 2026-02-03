#!/usr/bin/env php
<?php
/**
 * Example observe plugin: CPU usage. Copy to storage/app/observe_plugins/check_cpu.php.
 * Env: OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 * Args: warn_pct, crit_pct (optional). Note: CPU % requires sampling over time; this example uses load or a simple heuristic.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warnPct = (float) ($args['warn_pct'] ?? 80);
$critPct = (float) ($args['crit_pct'] ?? 95);
$host = getenv('OBSERVE_HOST_ADDRESS') ?: '127.0.0.1';

if ($host !== '127.0.0.1' && $host !== 'localhost') {
    echo "UNKNOWN - Remote CPU check not implemented (use NRPE on target)\n";
    exit(3);
}

// Simple approach: use load average as proxy (1-min load / core count). For real CPU % use two samples of /proc/stat.
$numCpus = 1;
if (is_readable('/proc/cpuinfo')) {
    $numCpus = substr_count(file_get_contents('/proc/cpuinfo'), 'processor');
    $numCpus = $numCpus >= 1 ? $numCpus : 1;
}
$load1 = 0.0;
if (is_readable('/proc/loadavg')) {
    $load = file_get_contents('/proc/loadavg');
    if (preg_match('/^([\d.]+)/', $load, $m)) {
        $load1 = (float) $m[1];
    }
}
$usagePct = $numCpus > 0 ? min(100, round(($load1 / $numCpus) * 100, 1)) : 0;

$perf = sprintf('cpu_usage=%.1f%%;%.0f;%.0f;0;100 load1=%.2f', $usagePct, $warnPct, $critPct, $load1);
if ($usagePct >= $critPct) {
    echo "CPU CRITICAL - {$usagePct}% (load {$load1}) | {$perf}\n";
    exit(2);
}
if ($usagePct >= $warnPct) {
    echo "CPU WARNING - {$usagePct}% (load {$load1}) | {$perf}\n";
    exit(1);
}
echo "CPU OK - {$usagePct}% (load {$load1}) | {$perf}\n";
exit(0);

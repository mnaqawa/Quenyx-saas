#!/usr/bin/env php
<?php
/**
 * Observe plugin: CPU usage. Copy to storage/app/observe_plugins/check_cpu.php.
 * Host and args from engine (UI: host under target; warn_pct/crit_pct from service config).
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warnPct = isset($args['warn_pct']) ? (float) $args['warn_pct'] : 80;
$critPct = isset($args['crit_pct']) ? (float) $args['crit_pct'] : 95;

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
    $load = is_readable('/proc/loadavg') ? file_get_contents('/proc/loadavg') : '';
    $cpuinfo = is_readable('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : '';
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " 'cat /proc/loadavg /proc/cpuinfo 2>/dev/null'";
    $out = (string) @shell_exec($cmd);
    $load = $out;
    $cpuinfo = $out;
}

$numCpus = 1;
if (preg_match_all('/\bprocessor\s*:/', $cpuinfo)) {
    $numCpus = preg_match_all('/\bprocessor\s*:/', $cpuinfo);
}
$numCpus = $numCpus >= 1 ? $numCpus : 1;
$load1 = 0.0;
if (preg_match('/^([\d.]+)/', $load, $m)) {
    $load1 = (float) $m[1];
}
$usagePct = $numCpus > 0 ? min(100.0, round(($load1 / $numCpus) * 100, 1)) : 0;

$perf = sprintf('cpu_usage=%.1f%%;%.0f;%.0f;0;100 load1=%.2f', $usagePct, $warnPct, $critPct, $load1);
if ($usagePct >= $critPct) {
    echo "CPU CRITICAL - {$usagePct}% ({$host}) | {$perf}\n";
    exit(2);
}
if ($usagePct >= $warnPct) {
    echo "CPU WARNING - {$usagePct}% ({$host}) | {$perf}\n";
    exit(1);
}
echo "CPU OK - {$usagePct}% ({$host}) | {$perf}\n";
exit(0);

#!/usr/bin/env php
<?php
/**
 * Observe plugin: Swap usage. Copy to storage/app/observe_plugins/check_swap.php.
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

$isLocal = in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)
    || (function_exists('gethostname') && strtolower(trim($host)) === strtolower(trim((string) gethostname())));

if ($isLocal) {
    $mem = is_readable('/proc/meminfo') ? file_get_contents('/proc/meminfo') : '';
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " 'cat /proc/meminfo 2>/dev/null'";
    $mem = (string) @shell_exec($cmd);
}

$total = $free = 0;
if (preg_match('/SwapTotal:\s+(\d+)/', $mem, $t)) {
    $total = (int) $t[1];
}
if (preg_match('/SwapFree:\s+(\d+)/', $mem, $f)) {
    $free = (int) $f[1];
}

if ($total === 0) {
    echo "SWAP OK - No swap configured ({$host})\n";
    exit(0);
}

$usedPct = round((($total - $free) / $total) * 100, 1);
$perf = sprintf('swap_used_pct=%.1f%%;%.0f;%.0f;0;100', $usedPct, $warnPct, $critPct);
if ($usedPct >= $critPct) {
    echo "SWAP CRITICAL - {$usedPct}% used ({$host}) | {$perf}\n";
    exit(2);
}
if ($usedPct >= $warnPct) {
    echo "SWAP WARNING - {$usedPct}% used ({$host}) | {$perf}\n";
    exit(1);
}
echo "SWAP OK - {$usedPct}% used ({$host}) | {$perf}\n";
exit(0);

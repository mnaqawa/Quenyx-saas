#!/usr/bin/env php
<?php
/**
 * Observe plugin: Disk space. Install with: php artisan observe:install-plugins
 * All values from UI: host (Monitored Targets), mount/warn_pct/crit_pct (service config). No hardcoded IP or thresholds.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$mount = isset($args['mount']) && $args['mount'] !== '' ? (string) $args['mount'] : '/';
$warnPct = isset($args['warn_pct']) ? (float) $args['warn_pct'] : 20;
$critPct = isset($args['crit_pct']) ? (float) $args['crit_pct'] : 10;

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
    $cmd = "df -P " . escapeshellarg($mount) . " 2>/dev/null | tail -1";
    $line = @shell_exec($cmd);
} else {
    $mountEsc = str_replace("'", "'\\''", $mount);
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " \"df -P '{$mountEsc}' 2>/dev/null | tail -1\"";
    $line = @shell_exec($cmd);
}

$usedPct = null;
if ($line && preg_match('/\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%/', trim($line), $m)) {
    $usedPct = (int) $m[4];
}
if ($usedPct === null) {
    echo "UNKNOWN - Could not get disk usage for {$mount} on {$host}\n";
    exit(3);
}

$freePct = 100 - $usedPct;
$perf = sprintf('free_pct=%.1f%%;%.0f;%.0f;0;100', $freePct, $warnPct, $critPct);
if ($freePct <= $critPct) {
    echo "DISK CRITICAL - {$freePct}% free on {$mount} ({$host}) | {$perf}\n";
    exit(2);
}
if ($freePct <= $warnPct) {
    echo "DISK WARNING - {$freePct}% free on {$mount} ({$host}) | {$perf}\n";
    exit(1);
}
echo "DISK OK - {$freePct}% free on {$mount} ({$host}) | {$perf}\n";
exit(0);

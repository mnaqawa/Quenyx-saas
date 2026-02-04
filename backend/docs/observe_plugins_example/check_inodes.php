#!/usr/bin/env php
<?php
/**
 * Observe plugin: Disk inodes. Copy to storage/app/observe_plugins/check_inodes.php.
 * Host and args from engine (UI: host under target; mount/warn_pct/crit_pct from service config).
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$mount = isset($args['mount']) && $args['mount'] !== '' ? (string) $args['mount'] : '/';
$warnFreePct = isset($args['warn_pct']) ? (float) $args['warn_pct'] : 15;
$critFreePct = isset($args['crit_pct']) ? (float) $args['crit_pct'] : 5;

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
    $cmd = "df -iP " . escapeshellarg($mount) . " 2>/dev/null | tail -1";
    $lineI = @shell_exec($cmd);
} else {
    $mountEsc = str_replace("'", "'\\''", $mount);
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " \"df -iP '{$mountEsc}' 2>/dev/null | tail -1\"";
    $lineI = @shell_exec($cmd);
}

$freePct = null;
if ($lineI && preg_match('/\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%/', trim($lineI), $m)) {
    $itotal = (int) $m[1];
    $iused = (int) $m[2];
    $freePct = $itotal > 0 ? round((($itotal - $iused) / $itotal) * 100, 1) : 0;
}

if ($freePct === null) {
    echo "UNKNOWN - Could not get inode usage for {$mount} on {$host}\n";
    exit(3);
}

$perf = sprintf("inode_free_pct=%.1f%%;%.0f;%.0f;0;100", $freePct, $warnFreePct, $critFreePct);
if ($freePct <= $critFreePct) {
    echo "INODES CRITICAL - {$freePct}% free on {$mount} ({$host}) | {$perf}\n";
    exit(2);
}
if ($freePct <= $warnFreePct) {
    echo "INODES WARNING - {$freePct}% free on {$mount} ({$host}) | {$perf}\n";
    exit(1);
}
echo "INODES OK - {$freePct}% free on {$mount} ({$host}) | {$perf}\n";
exit(0);

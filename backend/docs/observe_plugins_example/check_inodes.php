#!/usr/bin/env php
<?php
/**
 * Example observe plugin: Disk inodes. Copy to storage/app/observe_plugins/check_inodes.php.
 * Env: OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 * Args: mount (default /), warn_pct, crit_pct = free inode percentage thresholds.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$mount = $args['mount'] ?? '/';
$warnFreePct = (float) ($args['warn_pct'] ?? 15);
$critFreePct = (float) ($args['crit_pct'] ?? 5);
$host = getenv('OBSERVE_HOST_ADDRESS') ?: '127.0.0.1';

if ($host !== '127.0.0.1' && $host !== 'localhost') {
    echo "UNKNOWN - Remote inodes check not implemented (host={$host})\n";
    exit(3);
}

$freePct = null;
$cmdI = "df -iP " . escapeshellarg($mount) . " 2>/dev/null | tail -1";
$lineI = @shell_exec($cmdI);
if ($lineI && preg_match('/\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%/', trim($lineI), $m)) {
    $itotal = (int) $m[1];
    $iused = (int) $m[2];
    $freePct = $itotal > 0 ? round((($itotal - $iused) / $itotal) * 100, 1) : 0;
}

if ($freePct === null) {
    echo "UNKNOWN - Could not get inode usage for {$mount}\n";
    exit(3);
}

$usedPct = round(100 - $freePct, 1);
$perf = sprintf("inode_free_pct=%.1f%%;%.0f;%.0f;0;100", $freePct, $warnFreePct, $critFreePct);
if ($freePct <= $critFreePct) {
    echo "INODES CRITICAL - {$freePct}% free on {$mount} | {$perf}\n";
    exit(2);
}
if ($freePct <= $warnFreePct) {
    echo "INODES WARNING - {$freePct}% free on {$mount} | {$perf}\n";
    exit(1);
}
echo "INODES OK - {$freePct}% free on {$mount} | {$perf}\n";
exit(0);

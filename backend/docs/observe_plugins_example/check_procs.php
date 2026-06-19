#!/usr/bin/env php
<?php
/**
 * Observe plugin: Process count. Install with: php artisan observe:install-plugins
 * Host and thresholds from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
require_once __DIR__ . '/_observe_local.php';
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warn = isset($args['warn']) ? (int) $args['warn'] : 0;
$crit = isset($args['crit']) ? (int) $args['crit'] : 0;
$state = isset($args['state']) ? trim((string) $args['state']) : '';

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$isLocal = observe_is_local_host($host);

if ($isLocal) {
    $count = 0;
    if (is_dir('/proc')) {
        $dirs = @scandir('/proc');
        if ($dirs) {
            foreach ($dirs as $d) {
                if ($d !== '.' && $d !== '..' && ctype_digit($d) && is_dir("/proc/{$d}")) {
                    $count++;
                }
            }
        }
    }
    if ($count === 0) {
        $out = [];
        @exec('ps -e 2>/dev/null | wc -l', $out);
        $count = (int) trim($out[0] ?? '0') - 1;
        if ($count < 0) {
            $count = 0;
        }
    }
} else {
    $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no " . escapeshellarg($host) . " 'ps -e 2>/dev/null | wc -l'";
    $count = (int) trim((string) @shell_exec($cmd)) - 1;
    if ($count < 0) {
        $count = 0;
    }
}

$perf = sprintf('procs=%d;%s;%s;0', $count, $warn ?: '', $crit ?: '');
if ($crit > 0 && $count >= $crit) {
    echo "PROCS CRITICAL - {$count} processes ({$host}) | {$perf}\n";
    exit(2);
}
if ($warn > 0 && $count >= $warn) {
    echo "PROCS WARNING - {$count} processes ({$host}) | {$perf}\n";
    exit(1);
}
echo "PROCS OK - {$count} processes ({$host}) | {$perf}\n";
exit(0);

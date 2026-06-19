#!/usr/bin/env php
<?php
/**
 * Observe plugin: File age. Install with: php artisan observe:install-plugins
 */
require_once __DIR__ . '/_observe_local.php';

$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$path = trim((string) ($args['path'] ?? ''));
$warnSec = isset($args['warn_sec']) ? (int) $args['warn_sec'] : 86400;
$critSec = isset($args['crit_sec']) ? (int) $args['crit_sec'] : 172800;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address\n";
    exit(3);
}

if ($path === '') {
    echo "UNKNOWN - path is required\n";
    exit(3);
}

if (! observe_is_local_host($host)) {
    echo "UNKNOWN - File age check currently supports local hosts only\n";
    exit(3);
}

if (! is_file($path)) {
    echo "FILE CRITICAL - File not found: {$path}\n";
    exit(2);
}

$age = time() - (int) filemtime($path);
if ($critSec > 0 && $age >= $critSec) {
    echo "FILE CRITICAL - {$path} is {$age}s old (crit {$critSec}s)\n";
    exit(2);
}
if ($warnSec > 0 && $age >= $warnSec) {
    echo "FILE WARNING - {$path} is {$age}s old (warn {$warnSec}s)\n";
    exit(1);
}

echo "FILE OK - {$path} is {$age}s old\n";
exit(0);

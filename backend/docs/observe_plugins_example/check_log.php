#!/usr/bin/env php
<?php
/**
 * Observe plugin: Log pattern search. Install with: php artisan observe:install-plugins
 */
require_once __DIR__ . '/_observe_local.php';

$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$logfile = trim((string) ($args['logfile'] ?? ''));
$pattern = trim((string) ($args['pattern'] ?? ''));

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address\n";
    exit(3);
}
if ($logfile === '' || $pattern === '') {
    echo "UNKNOWN - logfile and pattern are required\n";
    exit(3);
}
if (! observe_is_local_host($host)) {
    echo "UNKNOWN - Log check currently supports local hosts only\n";
    exit(3);
}
if (! is_readable($logfile)) {
    echo "LOG CRITICAL - Cannot read {$logfile}\n";
    exit(2);
}

$matches = [];
$cmd = 'grep -E ' . escapeshellarg($pattern) . ' ' . escapeshellarg($logfile) . ' 2>/dev/null | tail -n 5';
exec($cmd, $matches);
if ($matches !== []) {
    echo "LOG CRITICAL - Pattern found in {$logfile}: " . trim($matches[0]) . "\n";
    exit(2);
}

echo "LOG OK - No matches for pattern in {$logfile}\n";
exit(0);

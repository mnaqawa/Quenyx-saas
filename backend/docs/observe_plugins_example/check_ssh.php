#!/usr/bin/env php
<?php
/**
 * Observe plugin: SSH port check. Install with: php artisan observe:install-plugins
 * Host and port from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS.port). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 22;
if ($port < 1 || $port > 65535) {
    $port = 22;
}

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$errno = 0;
$errstr = '';
$fp = @fsockopen($host, $port, $errno, $errstr, 10);
if ($fp !== false) {
    fclose($fp);
    echo "SSH OK - Port {$port} open on {$host}\n";
    exit(0);
}

$msg = $errstr ?: "Error {$errno}";
if (strpos($msg, 'refused') !== false || $errno === 111) {
    $msg = "Connection refused to {$host}:{$port}";
} elseif (strpos($msg, 'timed out') !== false || $errno === 110) {
    $msg = "Connection timed out to {$host}:{$port}";
}
echo "SSH CRITICAL - {$msg}\n";
exit(2);

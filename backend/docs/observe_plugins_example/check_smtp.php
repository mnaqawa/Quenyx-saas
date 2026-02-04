#!/usr/bin/env php
<?php
/**
 * Observe plugin: SMTP port check. Install with: php artisan observe:install-plugins
 * Host and port from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS.port). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 25;
if ($port < 1 || $port > 65535) {
    $port = 25;
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
    $banner = @fgets($fp, 512);
    fclose($fp);
    $code = $banner ? (int) substr(trim($banner), 0, 3) : 0;
    if ($code >= 200 && $code < 400) {
        echo "SMTP OK - Port {$port} on {$host} responded {$code}\n";
        exit(0);
    }
    echo "SMTP OK - Port {$port} open on {$host}\n";
    exit(0);
}

$msg = $errstr ?: "Error {$errno}";
echo "SMTP CRITICAL - {$msg} ({$host}:{$port})\n";
exit(2);

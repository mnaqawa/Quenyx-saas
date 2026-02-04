#!/usr/bin/env php
<?php
/**
 * Observe plugin: SSL certificate expiry. Install with: php artisan observe:install-plugins
 * Host and port/warn/crit from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 443;
$warnDays = isset($args['warn_days']) ? (int) $args['warn_days'] : 30;
$critDays = isset($args['crit_days']) ? (int) $args['crit_days'] : 7;
if ($port < 1 || $port > 65535) {
    $port = 443;
}

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$cmd = sprintf(
    'echo | openssl s_client -connect %s:%d -servername %s 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null',
    escapeshellarg($host),
    $port,
    escapeshellarg($host)
);
$out = trim((string) @shell_exec($cmd));
if ($out === '' || !preg_match('/notAfter=(.+)$/', $out, $m)) {
    echo "SSL UNKNOWN - Could not get certificate for {$host}:{$port}. Is openssl installed?\n";
    exit(3);
}

$endDate = trim($m[1]);
$endTime = strtotime($endDate);
if ($endTime === false) {
    echo "SSL UNKNOWN - Could not parse certificate end date\n";
    exit(3);
}

$daysLeft = (int) floor(($endTime - time()) / 86400);
$perf = sprintf('days_left=%d;%d;%d;0', $daysLeft, $warnDays, $critDays);

if ($daysLeft <= $critDays) {
    echo "SSL CRITICAL - Certificate expires in {$daysLeft} days ({$host}:{$port}) | {$perf}\n";
    exit(2);
}
if ($daysLeft <= $warnDays) {
    echo "SSL WARNING - Certificate expires in {$daysLeft} days ({$host}:{$port}) | {$perf}\n";
    exit(1);
}
echo "SSL OK - Certificate valid for {$daysLeft} days ({$host}:{$port}) | {$perf}\n";
exit(0);

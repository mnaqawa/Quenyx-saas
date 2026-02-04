#!/usr/bin/env php
<?php
/**
 * Observe plugin: NTP time offset. Install with: php artisan observe:install-plugins
 * Host (NTP server) and thresholds from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$warnOffset = isset($args['warn_offset']) ? (float) $args['warn_offset'] : 1.0;
$critOffset = isset($args['crit_offset']) ? (float) $args['crit_offset'] : 5.0;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$ntpServer = $host;
$socket = @fsockopen('udp://' . $ntpServer, 123, $errno, $errstr, 5);
if ($socket === false) {
    echo "NTP UNKNOWN - Could not reach {$ntpServer}:123 ({$errstr}). Install ntpdate or use check_ntp_peer.\n";
    exit(3);
}

$req = "\x1b" . str_repeat("\0", 47);
@fwrite($socket, $req);
@stream_set_timeout($socket, 2);
$resp = @fread($socket, 48);
fclose($socket);

if (strlen($resp) < 48) {
    echo "NTP UNKNOWN - No response from {$ntpServer}\n";
    exit(3);
}

$recv = unpack('N12', $resp);
$sec = isset($recv[11]) ? $recv[11] : 0;
$frac = isset($recv[12]) ? $recv[12] : 0;
if (PHP_INT_SIZE >= 8) {
    $t = ($sec - 2208988800) + ($frac / 4294967296.0);
} else {
    $t = floatval($sec - 2208988800) + ($frac / 4294967296.0);
}
$offset = $t - time();
$absOffset = abs($offset);
$perf = sprintf('offset=%.3fs;%.2f;%.2f', $offset, $warnOffset, $critOffset);

if ($absOffset >= $critOffset) {
    echo "NTP CRITICAL - Offset {$offset}s to {$host} | {$perf}\n";
    exit(2);
}
if ($absOffset >= $warnOffset) {
    echo "NTP WARNING - Offset {$offset}s to {$host} | {$perf}\n";
    exit(1);
}
echo "NTP OK - Offset {$offset}s to {$host} | {$perf}\n";
exit(0);

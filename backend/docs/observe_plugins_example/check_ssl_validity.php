#!/usr/bin/env php
<?php
/**
 * Observe plugin: SSL certificate expiry. Install with: php artisan observe:install-plugins
 * Supports url, urls (multi-line/comma), hostname, port, warn_days, crit_days.
 * Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$defaultPort = isset($args['port']) ? (int) $args['port'] : 443;
$warnDays = isset($args['warn_days']) ? (int) $args['warn_days'] : 30;
$critDays = isset($args['crit_days']) ? (int) $args['crit_days'] : 7;
$fallbackHost = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($fallbackHost === '') {
    $fallbackHost = '127.0.0.1';
}

$targets = ssl_parse_targets($args, $fallbackHost, $defaultPort);
if ($targets === []) {
    echo "SSL UNKNOWN - No hostname or URL configured\n";
    exit(3);
}

$worstExit = 0;
$messages = [];

foreach ($targets as $target) {
    [$exit, $message] = ssl_check_one($target['host'], $target['port'], $target['sni'], $warnDays, $critDays);
    $messages[] = $message;
    if ($exit > $worstExit) {
        $worstExit = $exit;
    }
}

echo implode(' | ', $messages) . "\n";
exit($worstExit);

/**
 * @param  array<string, mixed>  $args
 * @return array<int, array{host: string, port: int, sni: string}>
 */
function ssl_parse_targets(array $args, string $fallbackHost, int $defaultPort): array
{
    $targets = [];
    $rawList = [];

    if (! empty($args['urls'])) {
        $rawList = preg_split('/[\r\n,;]+/', trim((string) $args['urls'])) ?: [];
    } elseif (! empty($args['url'])) {
        $rawList = [trim((string) $args['url'])];
    } elseif (! empty($args['hostname'])) {
        $rawList = [trim((string) $args['hostname'])];
    } else {
        $rawList = [$fallbackHost];
    }

    foreach ($rawList as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        $targets[] = ssl_normalize_target($raw, $args, $defaultPort);
    }

    return $targets;
}

/**
 * @param  array<string, mixed>  $args
 * @return array{host: string, port: int, sni: string}
 */
function ssl_normalize_target(string $raw, array $args, int $defaultPort): array
{
    $host = $raw;
    $port = isset($args['port']) ? (int) $args['port'] : $defaultPort;
    $sni = $raw;

    if (preg_match('#^https?://#i', $raw)) {
        $parts = parse_url($raw);
        $host = $parts['host'] ?? $raw;
        $sni = $host;
        if (! empty($parts['port'])) {
            $port = (int) $parts['port'];
        } elseif (($parts['scheme'] ?? '') === 'https') {
            $port = 443;
        }
    } elseif (str_contains($raw, ':') && ! str_contains($raw, ' ')) {
        [$maybeHost, $maybePort] = explode(':', $raw, 2);
        if (ctype_digit($maybePort)) {
            $host = $maybeHost;
            $sni = $maybeHost;
            $port = (int) $maybePort;
        }
    }

    if ($port < 1 || $port > 65535) {
        $port = 443;
    }

    return ['host' => $host, 'port' => $port, 'sni' => $sni];
}

/**
 * @return array{0: int, 1: string}
 */
function ssl_check_one(string $host, int $port, string $sni, int $warnDays, int $critDays): array
{
    $connectHost = escapeshellarg($host);
    $sniHost = escapeshellarg($sni);
    $cmd = sprintf(
        'echo | openssl s_client -connect %s:%d -servername %s 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null',
        $connectHost,
        $port,
        $sniHost
    );
    $out = trim((string) @shell_exec($cmd));
    if ($out === '' || ! preg_match('/notAfter=(.+)$/', $out, $m)) {
        return [3, "SSL UNKNOWN - Could not get certificate for {$host}:{$port}"];
    }

    $endTime = strtotime(trim($m[1]));
    if ($endTime === false) {
        return [3, "SSL UNKNOWN - Could not parse certificate end date for {$host}:{$port}"];
    }

    $daysLeft = (int) floor(($endTime - time()) / 86400);

    if ($daysLeft <= $critDays) {
        return [2, "SSL CRITICAL - {$host}:{$port} expires in {$daysLeft} days"];
    }
    if ($daysLeft <= $warnDays) {
        return [1, "SSL WARNING - {$host}:{$port} expires in {$daysLeft} days"];
    }

    return [0, "SSL OK - {$host}:{$port} valid for {$daysLeft} days"];
}

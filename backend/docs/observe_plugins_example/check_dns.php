#!/usr/bin/env php
<?php
/**
 * Observe plugin: DNS resolution. Install with: php artisan observe:install-plugins
 * Server/hostname from UI (OBSERVE_CHECK_ARGS.server, .hostname) or host as target. No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$server = isset($args['server']) && trim((string) $args['server']) !== '' ? trim((string) $args['server']) : null;
$hostname = isset($args['hostname']) && trim((string) $args['hostname']) !== '' ? trim((string) $args['hostname']) : null;

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

$toResolve = $hostname ?? $host;
$resolved = @gethostbyname($toResolve);
if ($resolved === '' || $resolved === $toResolve) {
    echo "DNS CRITICAL - Could not resolve {$toResolve}\n";
    exit(2);
}
echo "DNS OK - {$toResolve} resolves to {$resolved}\n";
exit(0);

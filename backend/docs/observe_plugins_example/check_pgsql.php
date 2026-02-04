#!/usr/bin/env php
<?php
/**
 * Observe plugin: PostgreSQL connectivity. Install with: php artisan observe:install-plugins
 * Host, port, user from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 5432;
$user = isset($args['user']) ? trim((string) $args['user']) : 'postgres';

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

if ($port < 1 || $port > 65535) {
    $port = 5432;
}

if (extension_loaded('pdo_pgsql')) {
    try {
        $dsn = "pgsql:host={$host};port={$port};user={$user};connect_timeout=5";
        $pdo = new PDO($dsn, $user, isset($args['password']) ? (string) $args['password'] : '', [PDO::ATTR_TIMEOUT => 5]);
        echo "PGSQL OK - Connected to {$host}:{$port}\n";
        exit(0);
    } catch (PDOException $e) {
        echo "PGSQL CRITICAL - " . $e->getMessage() . "\n";
        exit(2);
    }
}

$errno = 0;
$errstr = '';
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if ($fp !== false) {
    fclose($fp);
    echo "PGSQL OK - Port {$port} open on {$host} (PHP pdo_pgsql not loaded; connection not verified)\n";
    exit(0);
}

echo "PGSQL CRITICAL - " . ($errstr ?: "Error {$errno}") . " ({$host}:{$port}). Install pdo_pgsql for full check.\n";
exit(2);

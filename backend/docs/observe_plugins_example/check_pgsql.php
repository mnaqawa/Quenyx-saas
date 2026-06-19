#!/usr/bin/env php
<?php
/**
 * Observe plugin: PostgreSQL connectivity. Install with: php artisan observe:install-plugins
 * Host/port/user/password from OBSERVE_CHECK_ARGS (host overrides OBSERVE_HOST_ADDRESS when set).
 * Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 5432;
$user = isset($args['user']) ? trim((string) $args['user']) : 'postgres';
$password = isset($args['password']) ? (string) $args['password'] : '';
$database = isset($args['database']) ? trim((string) $args['database']) : 'postgres';

$host = trim((string) ($args['host'] ?? getenv('OBSERVE_HOST_ADDRESS') ?: ''));
if ($host === '') {
    $host = '127.0.0.1';
}

if ($port < 1 || $port > 65535) {
    $port = 5432;
}

if (extension_loaded('pdo_pgsql')) {
    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$database};connect_timeout=5";
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_TIMEOUT => 5]);
        echo "PGSQL OK - Connected to {$host}:{$port}\n";
        exit(0);
    } catch (PDOException $e) {
        echo 'PGSQL CRITICAL - ' . $e->getMessage() . " ({$host}:{$port})\n";
        exit(2);
    }
}

$errno = 0;
$errstr = '';
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if ($fp !== false) {
    fclose($fp);
    echo "PGSQL WARNING - Port {$port} open on {$host} (PHP pdo_pgsql not loaded; connection not verified)\n";
    exit(1);
}

echo 'PGSQL CRITICAL - ' . ($errstr ?: "Error {$errno}") . " ({$host}:{$port}). Install pdo_pgsql for full check.\n";
exit(2);

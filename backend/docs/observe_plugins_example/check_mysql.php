#!/usr/bin/env php
<?php
/**
 * Observe plugin: MySQL connectivity. Install with: php artisan observe:install-plugins
 * Host/port/user/password from OBSERVE_CHECK_ARGS (host overrides OBSERVE_HOST_ADDRESS when set).
 * Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 3306;
$user = isset($args['user']) ? trim((string) $args['user']) : '';
$password = isset($args['password']) ? (string) $args['password'] : '';
$database = isset($args['database']) ? trim((string) $args['database']) : '';

$host = trim((string) ($args['host'] ?? getenv('OBSERVE_HOST_ADDRESS') ?: ''));
if ($host === '') {
    $host = '127.0.0.1';
}

if ($port < 1 || $port > 65535) {
    $port = 3306;
}

if (extension_loaded('mysqli')) {
    $mysqli = @new mysqli($host, $user !== '' ? $user : 'root', $password, $database !== '' ? $database : '', $port);
    if ($mysqli->connect_error) {
        echo 'MYSQL CRITICAL - ' . $mysqli->connect_error . " ({$host}:{$port})\n";
        exit(2);
    }
    $v = $mysqli->server_info;
    $mysqli->close();
    echo "MYSQL OK - Connected to {$host}:{$port} (server {$v})\n";
    exit(0);
}

if (extension_loaded('pdo_mysql')) {
    try {
        $dsn = "mysql:host={$host};port={$port};charset=utf8" . ($database !== '' ? ";dbname={$database}" : '');
        $pdo = new PDO($dsn, $user !== '' ? $user : 'root', $password, [PDO::ATTR_TIMEOUT => 5]);
        echo "MYSQL OK - Connected to {$host}:{$port}\n";
        exit(0);
    } catch (PDOException $e) {
        echo 'MYSQL CRITICAL - ' . $e->getMessage() . " ({$host}:{$port})\n";
        exit(2);
    }
}

$errno = 0;
$errstr = '';
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if ($fp !== false) {
    fclose($fp);
    echo "MYSQL WARNING - Port {$port} open on {$host} but PHP mysqli/pdo_mysql extension is not loaded\n";
    exit(1);
}

echo "MYSQL CRITICAL - Cannot reach {$host}:{$port}" . ($errstr !== '' ? " ({$errstr})" : '') . ". Install PHP mysqli or pdo_mysql for full authentication checks.\n";
exit(2);

#!/usr/bin/env php
<?php
/**
 * Observe plugin: MySQL connectivity. Install with: php artisan observe:install-plugins
 * Host, port, user from UI (OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS). No hardcoded values.
 * Env: OBSERVE_HOST_ADDRESS (required), OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 2=Critical, 3=Unknown.
 */
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 3306;
$user = isset($args['user']) ? trim((string) $args['user']) : '';
$password = isset($args['password']) ? (string) $args['password'] : '';

$host = trim((string) getenv('OBSERVE_HOST_ADDRESS'));
if ($host === '') {
    echo "UNKNOWN - No host address (set host in Monitored Targets)\n";
    exit(3);
}

if ($port < 1 || $port > 65535) {
    $port = 3306;
}

if (extension_loaded('mysqli')) {
    $mysqli = @new mysqli($host, $user ?: 'root', $password, '', $port);
    if ($mysqli->connect_error) {
        echo "MYSQL CRITICAL - " . $mysqli->connect_error . "\n";
        exit(2);
    }
    $v = $mysqli->server_info;
    $mysqli->close();
    echo "MYSQL OK - Connected to {$host}:{$port} (server {$v})\n";
    exit(0);
}

if (extension_loaded('pdo_mysql')) {
    try {
        $dsn = "mysql:host={$host};port={$port};charset=utf8";
        $pdo = new PDO($dsn, $user ?: 'root', $password, [PDO::ATTR_TIMEOUT => 5]);
        echo "MYSQL OK - Connected to {$host}:{$port}\n";
        exit(0);
    } catch (PDOException $e) {
        echo "MYSQL CRITICAL - " . $e->getMessage() . "\n";
        exit(2);
    }
}

echo "MYSQL UNKNOWN - PHP mysqli or pdo_mysql extension required\n";
exit(3);

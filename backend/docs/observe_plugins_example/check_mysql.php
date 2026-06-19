#!/usr/bin/env php
<?php
/**
 * Observe plugin: MySQL connectivity. Install with: php artisan observe:install-plugins
 * Prefer native in-process check (service_key mysql). This script is a fallback.
 */
require_once __DIR__ . '/_observe_local.php';

$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true) ?: [];
$port = isset($args['port']) ? (int) $args['port'] : 3306;
$user = isset($args['user']) ? trim((string) $args['user']) : '';
$password = isset($args['password']) ? (string) $args['password'] : '';
$database = isset($args['database']) ? trim((string) $args['database']) : '';

$host = trim((string) ($args['host'] ?? ''));
if ($host === '') {
    $host = trim((string) (getenv('OBSERVE_HOST_ADDRESS') ?: ''));
}
if ($host === '') {
    $host = '127.0.0.1';
}

if ($port < 1 || $port > 65535) {
    $port = 3306;
}

try {
    if (extension_loaded('mysqli')) {
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }
        $mysqli = @new mysqli($host, $user !== '' ? $user : 'root', $password, $database !== '' ? $database : '', $port);
        if ($mysqli->connect_errno) {
            echo 'MYSQL CRITICAL - ' . $mysqli->connect_error . " ({$host}:{$port})\n";
            exit(2);
        }
        $v = $mysqli->server_info;
        $mysqli->close();
        echo "MYSQL OK - Connected to {$host}:{$port} (server {$v})\n";
        exit(0);
    }

    if (extension_loaded('pdo_mysql')) {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4'
            . ($database !== '' ? ';dbname=' . $database : '');
        $pdo = new PDO($dsn, $user !== '' ? $user : 'root', $password, [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        unset($pdo);
        echo "MYSQL OK - Connected to {$host}:{$port}\n";
        exit(0);
    }

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($fp !== false) {
        fclose($fp);
        echo "MYSQL WARNING - Port {$port} open on {$host} but PHP mysqli/pdo_mysql extension is not loaded\n";
        exit(1);
    }

    echo "MYSQL CRITICAL - Cannot reach {$host}:{$port}" . ($errstr !== '' ? " ({$errstr})" : '') . ". Enable PHP mysqli or pdo_mysql.\n";
    exit(2);
} catch (Throwable $e) {
    echo 'MYSQL CRITICAL - ' . $e->getMessage() . " ({$host}:{$port})\n";
    exit(2);
}

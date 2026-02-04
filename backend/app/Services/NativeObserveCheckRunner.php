<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Runs observe checks natively in PHP (no Nagios daemon).
 * Supports http, tcp_port, ping and plugin (custom PHP/Perl/shell scripts).
 */
class NativeObserveCheckRunner
{
    private const CONNECT_TIMEOUT = 5;
    private const HTTP_TIMEOUT = 10;

    /**
     * Run one check. Returns ['state' => 'ok'|'warning'|'critical'|'unknown', 'output' => string, 'perfdata' => string|null].
     *
     * @param  string  $serviceKey  http, tcp_port, ping, plugin
     * @param  string  $hostAddress  Host IP or hostname
     * @param  array<string, mixed>  $checkArgs  Keys depend on service_key; for plugin: plugin (script name), plus any args for the script
     * @param  array{workspace_id?: int, host_name?: string, service_name?: string}  $context  Optional context for plugins (env vars)
     */
    public function run(string $serviceKey, string $hostAddress, array $checkArgs, array $context = []): array
    {
        $hostAddress = trim($hostAddress);
        if ($hostAddress === '' && $serviceKey !== 'plugin') {
            return ['state' => 'unknown', 'output' => 'No host address', 'perfdata' => null];
        }

        $key = strtolower($serviceKey);
        if ($key === 'http') {
            return $this->runHttp($hostAddress, $checkArgs);
        }
        if ($key === 'tcp_port') {
            return $this->runTcp($hostAddress, $checkArgs);
        }
        if ($key === 'ping') {
            return $this->runPing($hostAddress, $checkArgs);
        }
        if ($key === 'plugin') {
            return $this->runPlugin($hostAddress, $checkArgs, $context);
        }
        // Any other service_key (disk, load, cpu, etc.) is run as plugin: script name must be in check_args.plugin (caller sets it from check_command)
        if (($checkArgs['plugin'] ?? $checkArgs['script'] ?? '') !== '') {
            return $this->runPlugin($hostAddress, $checkArgs, $context);
        }
        return ['state' => 'unknown', 'output' => "Unsupported service_key: {$serviceKey}. Missing plugin script name (check_command).", 'perfdata' => null];
    }

    /**
     * HTTP check: GET host:port/path, compare status to expect (default 200).
     */
    private function runHttp(string $host, array $args): array
    {
        $port = (int) ($args['port'] ?? 80);
        $path = isset($args['path']) ? ('/' . trim((string) $args['path'], '/')) : '/';
        if ($path === '') {
            $path = '/';
        }
        $expect = isset($args['expect']) ? (int) $args['expect'] : 200;
        $scheme = ($port === 443 || ($args['use_ssl'] ?? false)) ? 'https' : 'http';
        $url = "{$scheme}://{$host}:{$port}{$path}";

        $start = microtime(true);
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->get($url);
            $elapsed = round((microtime(true) - $start) * 1000);
            $status = $response->status();
            $bodySize = strlen($response->body());

            if ($status === $expect) {
                return [
                    'state' => 'ok',
                    'output' => "HTTP OK: HTTP/1.1 {$status} - {$bodySize} bytes in " . round($elapsed / 1000, 3) . ' second response time',
                    'perfdata' => null,
                ];
            }
            if ($status >= 400 && $status < 500) {
                return [
                    'state' => 'warning',
                    'output' => "HTTP WARNING: HTTP/1.1 {$status} - {$bodySize} bytes in " . round($elapsed / 1000, 3) . ' second response time',
                    'perfdata' => null,
                ];
            }

            return [
                'state' => 'critical',
                'output' => "HTTP CRITICAL: HTTP/1.1 {$status} (expected {$expect}) - {$bodySize} bytes in " . round($elapsed / 1000, 3) . ' second response time',
                'perfdata' => null,
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Log::debug('NativeObserveCheckRunner HTTP check failed', ['url' => $url, 'error' => $msg]);

            return [
                'state' => 'critical',
                'output' => 'HTTP CRITICAL: ' . (str_contains($msg, 'Connection refused') ? 'Connection refused' : $msg),
                'perfdata' => null,
            ];
        }
    }

    /**
     * TCP port check: connect to host:port.
     */
    private function runTcp(string $host, array $args): array
    {
        $port = (int) ($args['port'] ?? 80);
        if ($port < 1 || $port > 65535) {
            return ['state' => 'unknown', 'output' => 'Invalid port', 'perfdata' => null];
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT);
        $elapsed = round((microtime(true) - $start) * 1000);

        if ($fp !== false) {
            fclose($fp);
            return [
                'state' => 'ok',
                'output' => "TCP OK - Port {$port} open (response in {$elapsed} ms)",
                'perfdata' => null,
            ];
        }

        $msg = $errstr ?: "Error {$errno}";
        if (str_contains($msg, 'refused') || $errno === 111) {
            $msg = "connect to address {$host} and port {$port}: Connection refused";
        } elseif (str_contains($msg, 'timed out') || $errno === 110) {
            $msg = "connect to address {$host} and port {$port}: Connection timed out";
        }

        return [
            'state' => 'critical',
            'output' => $msg,
            'perfdata' => null,
        ];
    }

    /**
     * Ping: run system ping and parse RTA/loss. Uses -c 1 (Linux/Mac) or -n 1 (Windows).
     */
    private function runPing(string $host, array $args): array
    {
        $warnRta = (float) ($args['warn_rta_ms'] ?? 100);
        $critRta = (float) ($args['crit_rta_ms'] ?? 500);
        $warnPl = (float) ($args['warn_pl_pct'] ?? 5);
        $critPl = (float) ($args['crit_pl_pct'] ?? 20);

        $cmd = $this->pingCommand($host);
        if ($cmd === null) {
            return ['state' => 'unknown', 'output' => 'Ping not available on this system', 'perfdata' => null];
        }

        $output = [];
        $exitCode = -1;
        @exec($cmd . ' 2>&1', $output, $exitCode);
        $outStr = implode(' ', $output);

        if ($exitCode !== 0) {
            $pl = 100.0;
            $rta = 0.0;
        } else {
            $rta = $this->parsePingRta($outStr);
            $pl = $this->parsePingLoss($outStr);
        }

        $perfdata = sprintf('rta=%.6fms;%.2f;%.2f;0 pl=%.0f%%;%.0f;%.0f;0', $rta, $warnRta, $critRta, $pl, $warnPl, $critPl);

        if ($pl >= $critPl || $rta >= $critRta) {
            $state = 'critical';
        } elseif ($pl >= $warnPl || $rta >= $warnRta) {
            $state = 'warning';
        } else {
            $state = 'ok';
        }

        $output = sprintf('PING %s - Packet loss = %.0f%%, RTA = %.2f ms', strtoupper($state), $pl, $rta);

        return ['state' => $state, 'output' => $output, 'perfdata' => $perfdata];
    }

    private function pingCommand(string $host): ?string
    {
        $safe = escapeshellarg($host);
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return "ping -n 1 -w 2000 {$safe}";
        }
        return "ping -c 1 -W 2 {$safe}";
    }

    private function parsePingRta(string $output): float
    {
        if (preg_match('/time[=<\s]+([\d.]+)\s*ms/i', $output, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/[\d.]+ ms/i', $output, $m)) {
            return (float) trim(str_replace('ms', '', $m[0]));
        }
        return 0.0;
    }

    private function parsePingLoss(string $output): float
    {
        if (preg_match('/(\d+)%?\s*packet loss/i', $output, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/(\d+)%\s*loss/i', $output, $m)) {
            return (float) $m[1];
        }
        return 0.0;
    }

    /**
     * Run a custom plugin script (PHP, Perl, or shell). Script receives env vars from the engine;
     * must exit 0=OK, 1=Warning, 2=Critical, 3=Unknown. Stdout: message, optional " | perfdata".
     */
    private function runPlugin(string $hostAddress, array $checkArgs, array $context): array
    {
        $pluginName = $checkArgs['plugin'] ?? $checkArgs['script'] ?? '';
        $pluginName = trim((string) $pluginName);
        if ($pluginName === '') {
            return ['state' => 'unknown', 'output' => 'Plugin name (plugin or script) required in check_args', 'perfdata' => null];
        }

        $pluginsDir = config('observe.plugins_dir', 'app/observe_plugins');
        $pluginsDir = str_starts_with($pluginsDir, '/') ? $pluginsDir : storage_path($pluginsDir);
        if (!is_dir($pluginsDir)) {
            @mkdir($pluginsDir, 0755, true);
        }
        if (!is_dir($pluginsDir)) {
            return ['state' => 'unknown', 'output' => 'Plugins directory not found: ' . $pluginsDir, 'perfdata' => null];
        }

        $base = basename($pluginName);
        if ($base !== $pluginName || str_contains($pluginName, '..')) {
            return ['state' => 'unknown', 'output' => 'Invalid plugin name (no path traversal)', 'perfdata' => null];
        }

        $path = null;
        $runner = null;
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext, ['php'], true)) {
            $path = $pluginsDir . DIRECTORY_SEPARATOR . $base;
            $runner = ['php', $path];
        } elseif (in_array($ext, ['pl'], true)) {
            $path = $pluginsDir . DIRECTORY_SEPARATOR . $base;
            $runner = ['perl', $path];
        } elseif (in_array($ext, ['sh'], true) || $ext === '') {
            $candidate = $pluginsDir . DIRECTORY_SEPARATOR . $base;
            if ($ext === '') {
                foreach (['.sh', '.php', '.pl'] as $e) {
                    if (is_file($candidate . $e)) {
                        $candidate .= $e;
                        $base .= $e;
                        $ext = ltrim($e, '.');
                        break;
                    }
                }
            }
            $path = $candidate;
            if (!is_file($path)) {
                return ['state' => 'unknown', 'output' => 'Plugin not found: ' . $base, 'perfdata' => null];
            }
            $runner = ['bash', $path];
        }

        if ($path === null || $runner === null || !is_file($path)) {
            return ['state' => 'unknown', 'output' => 'Plugin not found or unsupported type: ' . $base, 'perfdata' => null];
        }

        $realPath = realpath($path);
        $pluginsReal = realpath($pluginsDir);
        if ($realPath === false || $pluginsReal === false || !str_starts_with(str_replace('\\', '/', $realPath), str_replace('\\', '/', $pluginsReal))) {
            return ['state' => 'unknown', 'output' => 'Plugin path not allowed', 'perfdata' => null];
        }

        $env = [
            'OBSERVE_HOST_ADDRESS' => $hostAddress,
            'OBSERVE_CHECK_ARGS' => json_encode($checkArgs),
            'OBSERVE_SERVICE_NAME' => $context['service_name'] ?? '',
            'OBSERVE_WORKSPACE_ID' => (string) ($context['workspace_id'] ?? ''),
            'OBSERVE_HOST_NAME' => $context['host_name'] ?? '',
        ];

        $timeout = (float) config('observe.plugin_timeout_seconds', 30);
        $process = new Process($runner);
        $process->setTimeout($timeout);
        $process->setEnv($env);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('NativeObserveCheckRunner plugin failed', ['plugin' => $base, 'error' => $e->getMessage()]);
            return ['state' => 'unknown', 'output' => 'Plugin execution failed: ' . $e->getMessage(), 'perfdata' => null];
        }

        $stdout = $process->getOutput();
        $exitCode = $process->getExitCode();
        if ($exitCode === null) {
            $exitCode = $process->isSuccessful() ? 0 : 3;
        }

        $state = match ($exitCode) {
            0 => 'ok',
            1 => 'warning',
            2 => 'critical',
            default => 'unknown',
        };

        $output = trim($stdout);
        $perfdata = null;
        if (preg_match('/\s\|\s(.+)$/', $output, $m)) {
            $perfdata = trim($m[1]);
            $output = trim(preg_replace('/\s\|\s.+$/', '', $output));
        }
        if ($output === '') {
            $output = strtoupper($state) . ' - exit code ' . $exitCode;
        }

        return ['state' => $state, 'output' => $output, 'perfdata' => $perfdata];
    }
}

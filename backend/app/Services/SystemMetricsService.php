<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Collects real system metrics from the server (Linux /proc and /sys).
 * Returns structured data for Real-time Monitoring. Non-Linux or missing files return safe defaults.
 */
class SystemMetricsService
{
    /**
     * Get real-time metrics: CPU, memory, disk, network, temperature.
     * CPU and network require two samples; total request may take ~0.5s on Linux.
     *
     * @return array{cpu: array{value: int, cores: string, frequency: string}, memory: array{value: int, used: string, total: string}, diskIO: array{value: int, type: string, throughput: string}, network: array{value: int, speed: string, type: string}, temperature: array{value: int, source: string}}
     */
    public function getRealTimeMetrics(): array
    {
        $cpu = $this->getCpuMetrics();
        $memory = $this->getMemoryMetrics();
        $diskIO = $this->getDiskMetrics();
        $network = $this->getNetworkMetrics();
        $temperature = $this->getTemperatureMetrics();

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'diskIO' => $diskIO,
            'network' => $network,
            'temperature' => $temperature,
        ];
    }

    /**
     * Get system info: hostname, OS, kernel, uptime, load average.
     *
     * @return array{hostname: string, os: string, kernel: string, uptime: string, loadAverage: string}
     */
    public function getSystemInfo(): array
    {
        $hostname = $this->readHostname();
        $os = $this->readOs();
        $kernel = $this->readKernel();
        $uptime = $this->readUptime();
        $loadAverage = $this->readLoadAverage();

        return [
            'hostname' => $hostname,
            'os' => $os,
            'kernel' => $kernel,
            'uptime' => $uptime,
            'loadAverage' => $loadAverage,
        ];
    }

    private function getCpuMetrics(): array
    {
        if (! is_dir('/proc')) {
            return ['value' => 0, 'cores' => '—', 'frequency' => '—'];
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        $cores = $cpuinfo !== false ? preg_match_all('/^processor\s*:/m', $cpuinfo) : 0;
        if ($cores === 0 && PHP_OS_FAMILY === 'Linux') {
            $nproc = @shell_exec('nproc 2>/dev/null');
            $cores = $nproc !== null ? (int) trim($nproc) : 1;
        }
        if ($cores === 0) {
            $cores = 1;
        }
        $coresStr = $cores . ' core' . ($cores !== 1 ? 's' : '');

        // Sample /proc/stat twice to compute usage
        $stat1 = $this->readProcStat();
        if ($stat1 === null) {
            return ['value' => 0, 'cores' => $coresStr, 'frequency' => '—'];
        }
        usleep(300000); // 0.3s
        $stat2 = $this->readProcStat();
        if ($stat2 === null) {
            return ['value' => 0, 'cores' => $coresStr, 'frequency' => '—'];
        }

        $total1 = $stat1['user'] + $stat1['nice'] + $stat1['system'] + $stat1['idle'] + $stat1['iowait'] + $stat1['irq'] + $stat1['softirq'];
        $total2 = $stat2['user'] + $stat2['nice'] + $stat2['system'] + $stat2['idle'] + $stat2['iowait'] + $stat2['irq'] + $stat2['softirq'];
        $idleDelta = $stat2['idle'] - $stat1['idle'];
        $totalDelta = $total2 - $total1;
        $value = $totalDelta > 0 ? (int) round(100 - (100 * $idleDelta / $totalDelta)) : 0;
        $value = max(0, min(100, $value));

        $frequency = '—';
        if (is_readable('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq')) {
            $hz = (int) trim((string) file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq'));
            if ($hz > 0) {
                $frequency = round($hz / 1000, 1) . ' MHz';
            }
        }

        return [
            'value' => $value,
            'cores' => $coresStr,
            'frequency' => $frequency,
        ];
    }

    /** @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int}|null */
    private function readProcStat(): ?array
    {
        $line = @file_get_contents('/proc/stat');
        if ($line === false || $line === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim(explode("\n", $line)[0]), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 5) {
            return null;
        }
        return [
            'user' => (int) ($parts[1] ?? 0),
            'nice' => (int) ($parts[2] ?? 0),
            'system' => (int) ($parts[3] ?? 0),
            'idle' => (int) ($parts[4] ?? 0),
            'iowait' => (int) ($parts[5] ?? 0),
            'irq' => (int) ($parts[6] ?? 0),
            'softirq' => (int) ($parts[7] ?? 0),
        ];
    }

    private function getMemoryMetrics(): array
    {
        if (! is_readable('/proc/meminfo')) {
            return ['value' => 0, 'used' => '—', 'total' => '—'];
        }
        $mem = @file_get_contents('/proc/meminfo');
        if ($mem === false) {
            return ['value' => 0, 'used' => '—', 'total' => '—'];
        }
        $get = function (string $key): int {
            return (int) preg_replace('/\D/', '', $key);
        };
        $lines = [];
        foreach (explode("\n", $mem) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $lines[trim($k)] = (int) preg_replace('/\D/', '', $v);
            }
        }
        $memTotal = $lines['MemTotal'] ?? 0;
        $memAvailable = $lines['MemAvailable'] ?? ($lines['MemFree'] ?? 0);
        $memUsed = $memTotal - $memAvailable;
        $totalKb = $memTotal;
        $usedKb = $memUsed;
        $value = $totalKb > 0 ? (int) round(100 * $usedKb / $totalKb) : 0;
        $value = max(0, min(100, $value));
        $totalGb = round($totalKb / 1024 / 1024, 1);
        $usedGb = round($usedKb / 1024 / 1024, 1);
        return [
            'value' => $value,
            'used' => $usedGb . ' GB',
            'total' => $totalGb . ' GB',
        ];
    }

    private function getDiskMetrics(): array
    {
        $path = base_path();
        if (! is_dir($path)) {
            $path = '/';
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return ['value' => 0, 'type' => '—', 'throughput' => '—'];
        }
        $used = $total - $free;
        $value = (int) round(100 * $used / $total);
        $value = max(0, min(100, $value));
        $type = 'SSD';
        foreach (['sda', 'sdb', 'vda', 'nvme0n1'] as $block) {
            $rotFile = '/sys/block/' . $block . '/queue/rotational';
            if (is_readable($rotFile)) {
                if (trim((string) @file_get_contents($rotFile)) === '1') {
                    $type = 'HDD';
                }
                break;
            }
        }
        return [
            'value' => $value,
            'type' => $type,
            'throughput' => '—',
        ];
    }

    private function getNetworkMetrics(): array
    {
        if (! is_readable('/proc/net/dev')) {
            return ['value' => 0, 'speed' => '—', 'type' => '—'];
        }
        $dev1 = @file_get_contents('/proc/net/dev');
        if ($dev1 === false) {
            return ['value' => 0, 'speed' => '1 Gbps', 'type' => 'Ethernet'];
        }
        usleep(200000); // 0.2s
        $dev2 = @file_get_contents('/proc/net/dev');
        if ($dev2 === false) {
            return ['value' => 0, 'speed' => '1 Gbps', 'type' => 'Ethernet'];
        }
        $bytes1 = $this->sumNetDevBytes($dev1);
        $bytes2 = $this->sumNetDevBytes($dev2);
        $delta = $bytes2 - $bytes1;
        $bps = $delta * 4; // ~0.2s -> *4 for per-second rate (approx)
        $gbps = $bps / 1e9;
        $value = (int) round(min(100, $gbps * 100)); // assume 1 Gbps max for %
        return [
            'value' => max(0, $value),
            'speed' => '1 Gbps',
            'type' => 'Ethernet',
        ];
    }

    private function sumNetDevBytes(string $content): int
    {
        $sum = 0;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Inter') || str_starts_with($line, 'face')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) >= 10) {
                $sum += (int) $parts[1]; // receive bytes
                $sum += (int) $parts[9]; // transmit bytes
            }
        }
        return $sum;
    }

    private function getTemperatureMetrics(): array
    {
        $value = 0;
        $source = '—';
        $base = '/sys/class/thermal';
        if (! is_dir($base)) {
            return ['value' => 0, 'source' => $source];
        }
        $dirs = glob($base . '/thermal_zone*');
        if ($dirs === false) {
            return ['value' => 0, 'source' => $source];
        }
        foreach ($dirs as $dir) {
            $tempFile = $dir . '/temp';
            $typeFile = $dir . '/type';
            if (is_readable($tempFile)) {
                $t = (int) trim((string) file_get_contents($tempFile));
                if ($t > 0) {
                    $value = (int) round($t / 1000);
                    $source = is_readable($typeFile)
                        ? trim((string) file_get_contents($typeFile))
                        : 'thermal_zone';
                    break;
                }
            }
        }
        return ['value' => $value, 'source' => $source];
    }

    private function readHostname(): string
    {
        $h = gethostname();
        return $h !== false ? $h : '—';
    }

    private function readOs(): string
    {
        if (is_readable('/etc/os-release')) {
            $content = @file_get_contents('/etc/os-release');
            if ($content !== false && preg_match('/PRETTY_NAME="([^"]+)"/', $content, $m)) {
                return $m[1];
            }
        }
        return PHP_OS;
    }

    private function readKernel(): string
    {
        $u = php_uname('r');
        return $u !== false ? $u : '—';
    }

    private function readUptime(): string
    {
        if (! is_readable('/proc/uptime')) {
            return '—';
        }
        $s = trim((string) @file_get_contents('/proc/uptime'));
        $sec = (float) explode(' ', $s)[0];
        $d = (int) ($sec / 86400);
        $h = (int) (($sec % 86400) / 3600);
        $m = (int) (($sec % 3600) / 60);
        $parts = [];
        if ($d > 0) {
            $parts[] = $d . 'd';
        }
        $parts[] = $h . 'h';
        $parts[] = $m . 'm';
        return implode(' ', $parts);
    }

    private function readLoadAverage(): string
    {
        if (! is_readable('/proc/loadavg')) {
            return '—';
        }
        $line = trim((string) @file_get_contents('/proc/loadavg'));
        $parts = explode(' ', $line);
        return implode(', ', array_slice($parts, 0, 3));
    }
}

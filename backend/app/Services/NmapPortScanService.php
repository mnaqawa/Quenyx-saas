<?php

namespace App\Services;

use App\Models\HostPortScan;
use App\Models\HostPortScanResult;
use App\Models\ObserveTargetHost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Runs nmap port scans on target hosts and persists results.
 * Requires nmap to be installed on the server (e.g. apt install nmap).
 *
 * @param array{ports?: string, ports_range?: string, protocol?: string} $options
 *   - ports: 'top100' | 'all' | 'range' (default: top100)
 *   - ports_range: e.g. '1-1024' or '80,443,8080' when ports=range
 *   - protocol: 'tcp' | 'udp' (default: tcp). UDP may require root on Linux.
 */
class NmapPortScanService
{
    /** Timeout in seconds */
    private const TIMEOUT = 120;

    /** Max timeout for all-ports scan */
    private const TIMEOUT_ALL_PORTS = 600;

    /**
     * @param  array{ports?: string, ports_range?: string, protocol?: string}  $options
     */
    public function runScan(ObserveTargetHost $host, array $options = []): HostPortScan
    {
        $scan = HostPortScan::create([
            'host_id' => $host->id,
            'status' => 'running',
        ]);

        $address = trim((string) $host->address);
        if ($address === '') {
            $scan->update([
                'status' => 'failed',
                'error_message' => 'Host address is empty',
            ]);
            return $scan;
        }

        // Sanitize address for shell (basic safety)
        $address = preg_replace('/[^a-zA-Z0-9.\-_:\/]/', '', $address);
        if ($address === '') {
            $scan->update([
                'status' => 'failed',
                'error_message' => 'Invalid host address after sanitization',
            ]);
            return $scan;
        }

        $portsMode = $options['ports'] ?? 'top100';
        $portsRange = trim((string) ($options['ports_range'] ?? ''));
        $protocol = strtolower($options['protocol'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp';

        $portsArg = $this->buildPortsArg($portsMode, $portsRange);
        $timeout = ($portsMode === 'all') ? self::TIMEOUT_ALL_PORTS : (int) config('observe.nmap_timeout_seconds', self::TIMEOUT);

        // -sT: TCP connect scan (no root required). -sU: UDP scan (may need root on Linux)
        $scanType = $protocol === 'udp' ? '-sU' : '-sT';
        $command = ['nmap', $scanType, '-oX', '-', $address];
        $portsParts = explode(' ', trim($portsArg));
        array_splice($command, 2, 0, $portsParts);

        try {
            $process = Process::timeout($timeout)->run($command);

            if (!$process->successful()) {
                $scan->update([
                    'status' => 'failed',
                    'error_message' => 'nmap failed: ' . trim($process->errorOutput() ?: $process->output() ?: 'Unknown error'),
                ]);
                Log::warning('NmapPortScanService: scan failed', [
                    'host_id' => $host->id,
                    'address' => $host->address,
                    'exit_code' => $process->exitCode(),
                ]);
                return $scan;
            }

            $xml = $process->output();
            $results = $this->parseNmapXml($xml);

            foreach ($results as $row) {
                HostPortScanResult::create([
                    'scan_id' => $scan->id,
                    'port' => $row['port'],
                    'protocol' => $row['protocol'] ?? 'tcp',
                    'state' => $row['state'] ?? 'open',
                    'service' => $row['service'] ?? null,
                    'version' => $row['version'] ?? null,
                ]);
            }

            $openCount = count(array_filter($results, fn ($r) => ($r['state'] ?? '') === 'open'));

            $scan->update([
                'status' => 'completed',
                'scanned_at' => now(),
                'open_ports_count' => $openCount,
                'error_message' => null,
            ]);

            Log::info('NmapPortScanService: scan completed', [
                'host_id' => $host->id,
                'address' => $host->address,
                'open_ports' => $openCount,
                'total_ports' => count($results),
            ]);
        } catch (\Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('NmapPortScanService: scan exception', [
                'host_id' => $host->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $scan;
    }

    /**
     * Parse nmap XML output into port result rows.
     *
     * @return array<int, array{port: int, protocol: string, state: string, service?: string, version?: string}>
     */
    private function parseNmapXml(string $xml): array
    {
        $results = [];
        $useErrors = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml)) {
                return [];
            }

            $xpath = new \DOMXPath($doc);
            $ports = $xpath->query('//*[local-name()="port"]');
            foreach ($ports as $portNode) {
                $portId = (int) $portNode->getAttribute('portid');
                $protocol = $portNode->getAttribute('protocol') ?: 'tcp';

                $stateNodes = $xpath->query('.//*[local-name()="state"]', $portNode);
                $stateNode = $stateNodes->item(0);
                $state = $stateNode ? $stateNode->getAttribute('state') : 'unknown';

                $serviceNodes = $xpath->query('.//*[local-name()="service"]', $portNode);
                $serviceNode = $serviceNodes->item(0);
                $service = $serviceNode ? $serviceNode->getAttribute('name') : null;
                $version = $serviceNode ? trim($serviceNode->getAttribute('product') . ' ' . $serviceNode->getAttribute('version')) : null;
                $version = $version !== '' ? $version : null;

                $results[] = [
                    'port' => $portId,
                    'protocol' => $protocol,
                    'state' => $state,
                    'service' => $service,
                    'version' => $version,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('NmapPortScanService: XML parse failed', ['error' => $e->getMessage()]);
        } finally {
            libxml_use_internal_errors($useErrors);
        }

        return $results;
    }

    /**
     * Build nmap ports argument from options.
     */
    private function buildPortsArg(string $portsMode, string $portsRange): string
    {
        if ($portsMode === 'all') {
            return '-p-';
        }
        if ($portsMode === 'range' && $portsRange !== '') {
            // Validate: allow 1-65535, comma-separated ports, or mix like "80,443,8000-9000"
            $sanitized = preg_replace('/[^0-9,\-\s]/', '', $portsRange);
            $sanitized = preg_replace('/\s+/', '', $sanitized);
            if ($sanitized !== '') {
                return '-p ' . $sanitized;
            }
        }
        return '--top-ports 100';
    }
}

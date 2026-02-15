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
 */
class NmapPortScanService
{
    /** Default: top 100 most common ports for faster scans */
    private const DEFAULT_PORTS = '--top-ports 100';

    /** Timeout in seconds */
    private const TIMEOUT = 120;

    public function runScan(ObserveTargetHost $host): HostPortScan
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

        $ports = config('observe.nmap_ports', self::DEFAULT_PORTS);
        $timeout = (int) config('observe.nmap_timeout_seconds', self::TIMEOUT);

        // -sT: TCP connect scan (no root required)
        // -oX -: XML output to stdout
        $command = ['nmap', '-sT', '-oX', '-', $address];
        $portsParts = explode(' ', trim($ports));
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
}

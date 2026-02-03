<?php

namespace App\Console\Commands;

use App\Models\ObserveService;
use App\Models\ObserveMeta;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PollObserveData extends Command
{
    protected $signature = 'observe:poll {--workspace_id=}';
    protected $description = 'Poll Nagios data from gateway and store in database';

    private string $gatewayUrl;
    private string $internalSecret;

    public function __construct()
    {
        parent::__construct();
        $this->gatewayUrl = config('app.gateway_url', 'http://127.0.0.1:4000');
        $this->internalSecret = config('app.gateway_internal_secret', 'dev-secret-change-in-production');
    }

    public function handle(): int
    {
        $workspaceId = $this->option('workspace_id');
        
        if ($workspaceId) {
            $workspace = Project::find($workspaceId);
            if (!$workspace) {
                $this->error("Workspace {$workspaceId} not found");
                return 1;
            }
            return $this->pollWorkspace($workspace) ? 0 : 1;
        }

        // Poll all workspaces
        $workspaces = Project::all();
        $successCount = 0;
        $failCount = 0;

        foreach ($workspaces as $workspace) {
            if ($this->pollWorkspace($workspace)) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        $this->info("Polled {$successCount} workspace(s) successfully, {$failCount} failed");
        return $failCount > 0 ? 1 : 0;
    }

    /**
     * Infer service state from plugin/output text when gateway sends pending.
     * Matches common Nagios plugin output (e.g. "PING OK", "HTTP WARNING", "CRITICAL", "Connection refused").
     */
    private function inferStateFromOutput(string $output): ?string
    {
        if ($output === '') {
            return null;
        }
        $lower = strtolower($output);
        if (str_contains($lower, 'critical') || str_contains($lower, 'connection refused') || str_contains($lower, 'connection timed out')) {
            return 'critical';
        }
        if (str_contains($lower, 'warning') || str_contains($lower, 'warn ')) {
            return 'warning';
        }
        if (str_contains($lower, ' ok ') || str_contains($lower, 'ok -') || preg_match('/\bok\b/i', $lower)) {
            return 'ok';
        }
        if (str_contains($lower, 'unknown')) {
            return 'unknown';
        }
        return null;
    }

    /**
     * Parse gateway timestamp to DateTime. Accepts ISO string or Unix timestamp (seconds or milliseconds).
     */
    private function parseDateTime(mixed $value): ?\DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $ts = (float) $value;
                // If value looks like seconds (<= 10 digits), treat as seconds
                $ts = $ts <= 9999999999 ? $ts : $ts / 1000;
                $dt = new \DateTime();
                $dt->setTimestamp((int) $ts);
                return $dt;
            }
            if (is_string($value)) {
                return new \DateTime($value);
            }
        } catch (\Exception $e) {
            // ignore invalid date
        }
        return null;
    }

    private function pollWorkspace(Project $workspace): bool
    {
        try {
            $this->info("Polling workspace {$workspace->id} ({$workspace->name})...");

            // Fetch services from gateway with workspace scoping via host_prefix
            $workspacePrefix = 'ws' . $workspace->id . '-';
            $servicesUrl = "{$this->gatewayUrl}/internal/engines/nagios/services";
            $servicesResponse = Http::timeout(60)
                ->withHeaders([
                    'x-internal-secret' => $this->internalSecret,
                ])
                ->get($servicesUrl, [
                    'host_prefix' => $workspacePrefix,
                ]);

            if (!$servicesResponse->successful()) {
                $errorBody = $servicesResponse->body();
                if ($servicesResponse->status() === 404) {
                    throw new \Exception("Gateway internal route not found (404). Gateway may not be updated or routes not mounted. URL: {$servicesUrl}");
                }
                throw new \Exception("Gateway returned {$servicesResponse->status()}: {$errorBody}");
            }

            $servicesData = $servicesResponse->json();
            if (!isset($servicesData['success']) || !$servicesData['success'] || !isset($servicesData['data'])) {
                throw new \Exception('Invalid response format from gateway');
            }

            $services = $servicesData['data'];
            if (!is_array($services)) {
                $services = [];
            }

            // Fetch summary
            $summaryUrl = "{$this->gatewayUrl}/internal/engines/nagios/summary";
            $summaryResponse = Http::timeout(30)
                ->withHeaders([
                    'x-internal-secret' => $this->internalSecret,
                ])
                ->get($summaryUrl);

            $summary = null;
            if ($summaryResponse->successful()) {
                $summaryData = $summaryResponse->json();
                if (isset($summaryData['success']) && $summaryData['success'] && isset($summaryData['data'])) {
                    $summary = $summaryData['data'];
                }
            }

            // Upsert services (gateway already filtered by host_prefix, but double-check for safety)
            $workspacePrefix = 'ws' . $workspace->id . '-';
            DB::transaction(function () use ($workspace, $services, $summary, $workspacePrefix) {
                $engineKey = 'nagios';
                $receivedKeys = [];

                foreach ($services as $index => $service) {
                    if (!is_array($service)) {
                        Log::warning('PollObserveData: skipping non-array service row', ['workspace_id' => $workspace->id, 'index' => $index]);
                        continue;
                    }
                    $hostName = $service['host_name'] ?? $service['host'] ?? $service['hostname'] ?? $service['name'] ?? '';
                    $serviceName = $service['service_name'] ?? $service['service_description'] ?? $service['description'] ?? $service['service'] ?? $service['name'] ?? '';
                    $hostName = is_string($hostName) ? trim($hostName) : '';
                    $serviceName = is_string($serviceName) ? trim($serviceName) : '';
                    if ($hostName === '') {
                        Log::debug('PollObserveData: skipping row missing host identifier', [
                            'workspace_id' => $workspace->id,
                            'index' => $index,
                            'keys' => array_keys($service),
                        ]);
                        continue;
                    }
                    // Gateway sometimes returns empty service_name (e.g. Nagios detail uses different key). Match by host + row index to existing observe_services so we update sync-created rows (HTTP Check, etc.) instead of creating service-0, service-1.
                    if ($serviceName === '') {
                        $existingForHost = ObserveService::where('workspace_id', $workspace->id)
                            ->where('engine_key', $engineKey)
                            ->where('host_name', $hostName)
                            ->orderBy('id')
                            ->get();
                        $existing = $existingForHost->get($index);
                        if ($existing !== null) {
                            $serviceName = $existing->service_name;
                            Log::debug('PollObserveData: resolved empty service name from existing row by index', [
                                'workspace_id' => $workspace->id,
                                'index' => $index,
                                'service_name' => $serviceName,
                            ]);
                        } else {
                            $serviceName = 'service-' . $index;
                            Log::debug('PollObserveData: using placeholder service name for row with empty identifier', [
                                'workspace_id' => $workspace->id,
                                'index' => $index,
                                'placeholder' => $serviceName,
                            ]);
                        }
                    }
                    // Gateway should have already filtered, but verify for safety
                    if (!str_starts_with($hostName, $workspacePrefix)) {
                        continue;
                    }

                    $engineServiceKey = "{$hostName}::{$serviceName}";
                    $receivedKeys[] = $engineServiceKey;

                    $state = $service['state'] ?? $service['status'] ?? 'unknown';
                    if (is_numeric($state)) {
                        $state = match ((int) $state) {
                            0 => 'ok',
                            1 => 'warning',
                            2 => 'critical',
                            3 => 'unknown',
                            default => 'pending',
                        };
                    } else {
                        $state = is_string($state) ? strtolower(trim($state)) : 'unknown';
                    }
                    if (!in_array($state, ['ok', 'warning', 'critical', 'unknown', 'pending', 'unreachable'], true)) {
                        $state = 'unknown';
                    }
                    // If gateway still sends pending but we have plugin output, infer state from output so UI shows correct status
                    if ($state === 'pending') {
                        $inferred = $this->inferStateFromOutput(
                            $service['plugin_output'] ?? $service['output'] ?? ''
                        );
                        if ($inferred !== null) {
                            $state = $inferred;
                        }
                    }

                    $lastCheckAt = $this->parseDateTime($service['last_check_at'] ?? null);
                    $nextCheckAt = $this->parseDateTime($service['next_check_at'] ?? null);
                    $lastStateChangeAt = $this->parseDateTime($service['last_state_change_at'] ?? null);

                    ObserveService::updateOrCreate(
                        [
                            'workspace_id' => $workspace->id,
                            'engine_key' => $engineKey,
                            'engine_service_key' => $engineServiceKey,
                        ],
                        [
                            'host_name' => $hostName,
                            'service_name' => $serviceName,
                            'state' => $state,
                            'last_check_at' => $lastCheckAt,
                            'next_check_at' => $nextCheckAt,
                            'duration_sec' => isset($service['duration_sec']) ? (int) $service['duration_sec'] : null,
                            'attempt' => isset($service['attempt']) ? (string) $service['attempt'] : null,
                            'current_attempt' => isset($service['current_attempt']) ? (int) $service['current_attempt'] : null,
                            'max_attempts' => isset($service['max_attempts']) ? (int) $service['max_attempts'] : null,
                            'state_type' => isset($service['state_type']) ? (string) $service['state_type'] : null,
                            'output' => isset($service['output']) ? (string) $service['output'] : null,
                            'plugin_output' => isset($service['plugin_output']) ? (string) $service['plugin_output'] : null,
                            'long_plugin_output' => isset($service['long_plugin_output']) ? (string) $service['long_plugin_output'] : null,
                            'perfdata' => isset($service['perfdata']) ? (string) $service['perfdata'] : null,
                            'check_command' => isset($service['check_command']) ? (string) $service['check_command'] : null,
                            'check_latency_sec' => isset($service['check_latency_sec']) ? (float) $service['check_latency_sec'] : null,
                            'execution_time_sec' => isset($service['execution_time_sec']) ? (float) $service['execution_time_sec'] : null,
                            'last_state_change_at' => $lastStateChangeAt,
                        ]
                    );
                }

                // Remove services no longer in Nagios (e.g. after target/host removal) so Services page reflects removals
                if (!empty($receivedKeys)) {
                    ObserveService::where('workspace_id', $workspace->id)
                        ->where('engine_key', $engineKey)
                        ->whereNotIn('engine_service_key', $receivedKeys)
                        ->delete();
                } else {
                    // No services from Nagios for this workspace: clear all for this workspace/engine
                    ObserveService::where('workspace_id', $workspace->id)
                        ->where('engine_key', $engineKey)
                        ->delete();
                }

                // Update meta
                $totals = $summary['totals'] ?? null;
                ObserveMeta::updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'engine_key' => $engineKey,
                    ],
                    [
                        'last_poll_at' => now(),
                        'service_totals_json' => $totals,
                        'error' => null,
                    ]
                );
            });

            $this->info("Successfully polled {$workspace->id}: " . count($services) . " services");
            return true;

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error("PollObserveData failed for workspace {$workspace->id}", [
                'workspace_id' => $workspace->id,
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString(),
            ]);

            // Degraded mode (TPM E): persist UNREACHABLE state so UI shows "Engine unreachable"
            try {
                $engineKey = 'nagios';
                ObserveService::where('workspace_id', $workspace->id)
                    ->where('engine_key', $engineKey)
                    ->update([
                        'state' => 'unreachable',
                        'output' => 'Engine unreachable',
                        'last_check_at' => null,
                    ]);
                ObserveMeta::updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'engine_key' => $engineKey,
                    ],
                    [
                        'error' => $errorMessage,
                        'last_poll_at' => now(),
                    ]
                );
            } catch (\Exception $metaError) {
                Log::error("Failed to persist unreachable state", [
                    'workspace_id' => $workspace->id,
                    'error' => $metaError->getMessage(),
                ]);
            }

            $this->error("Failed to poll workspace {$workspace->id}: {$errorMessage}");
            return false;
        }
    }
}

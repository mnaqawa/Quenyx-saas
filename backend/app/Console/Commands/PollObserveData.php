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

                foreach ($services as $service) {
                    // Gateway should have already filtered, but verify for safety
                    if (!str_starts_with($service['host_name'], $workspacePrefix)) {
                        continue;
                    }
                    
                    $engineServiceKey = "{$service['host_name']}::{$service['service_name']}";
                    $receivedKeys[] = $engineServiceKey;
                    
                    ObserveService::updateOrCreate(
                        [
                            'workspace_id' => $workspace->id,
                            'engine_key' => $engineKey,
                            'engine_service_key' => $engineServiceKey,
                        ],
                        [
                            'host_name' => $service['host_name'],
                            'service_name' => $service['service_name'],
                            'state' => $service['state'],
                            'last_check_at' => !empty($service['last_check_at']) ? new \DateTime($service['last_check_at']) : null,
                            'next_check_at' => !empty($service['next_check_at']) ? new \DateTime($service['next_check_at']) : null,
                            'duration_sec' => $service['duration_sec'] ?? null,
                            'attempt' => $service['attempt'] ?? null,
                            'current_attempt' => $service['current_attempt'] ?? null,
                            'max_attempts' => $service['max_attempts'] ?? null,
                            'state_type' => $service['state_type'] ?? null,
                            'output' => $service['output'] ?? null,
                            'plugin_output' => $service['plugin_output'] ?? null,
                            'long_plugin_output' => $service['long_plugin_output'] ?? null,
                            'perfdata' => $service['perfdata'] ?? null,
                            'check_command' => $service['check_command'] ?? null,
                            'check_latency_sec' => isset($service['check_latency_sec']) ? (float) $service['check_latency_sec'] : null,
                            'execution_time_sec' => isset($service['execution_time_sec']) ? (float) $service['execution_time_sec'] : null,
                            'last_state_change_at' => !empty($service['last_state_change_at']) ? new \DateTime($service['last_state_change_at']) : null,
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

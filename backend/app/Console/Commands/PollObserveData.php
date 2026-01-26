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
        $this->gatewayUrl = config('app.gateway_url');
        $this->internalSecret = config('app.gateway_internal_secret');
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

            // Fetch services from gateway
            $servicesResponse = Http::timeout(60)
                ->withHeaders([
                    'x-internal-secret' => $this->internalSecret,
                ])
                ->get("{$this->gatewayUrl}/internal/engines/nagios/services");

            if (!$servicesResponse->successful()) {
                throw new \Exception("Gateway returned {$servicesResponse->status()}: {$servicesResponse->body()}");
            }

            $servicesData = $servicesResponse->json();
            if (!isset($servicesData['success']) || !$servicesData['success'] || !isset($servicesData['data'])) {
                throw new \Exception('Invalid response format from gateway');
            }

            $services = $servicesData['data'];

            // Fetch summary
            $summaryResponse = Http::timeout(30)
                ->withHeaders([
                    'x-internal-secret' => $this->internalSecret,
                ])
                ->get("{$this->gatewayUrl}/internal/engines/nagios/summary");

            $summary = null;
            if ($summaryResponse->successful()) {
                $summaryData = $summaryResponse->json();
                if (isset($summaryData['success']) && $summaryData['success'] && isset($summaryData['data'])) {
                    $summary = $summaryData['data'];
                }
            }

            // Upsert services
            DB::transaction(function () use ($workspace, $services, $summary) {
                $engineKey = 'nagios';
                
                foreach ($services as $service) {
                    $engineServiceKey = "{$service['host_name']}::{$service['service_name']}";
                    
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
                            'last_check_at' => $service['last_check_at'] ? new \DateTime($service['last_check_at']) : null,
                            'duration_sec' => $service['duration_sec'] ?? null,
                            'attempt' => $service['attempt'] ?? null,
                            'output' => $service['output'] ?? null,
                            'perfdata' => $service['perfdata'] ?? null,
                        ]
                    );
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

            // Store error in meta (don't delete existing data)
            try {
                ObserveMeta::updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'engine_key' => 'nagios',
                    ],
                    [
                        'error' => $errorMessage,
                    ]
                );
            } catch (\Exception $metaError) {
                Log::error("Failed to store error in meta", [
                    'workspace_id' => $workspace->id,
                    'error' => $metaError->getMessage(),
                ]);
            }

            $this->error("Failed to poll workspace {$workspace->id}: {$errorMessage}");
            return false;
        }
    }
}

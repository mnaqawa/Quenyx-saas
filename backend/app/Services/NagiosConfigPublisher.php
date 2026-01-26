<?php

namespace App\Services;

use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\ObserveMeta;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NagiosConfigPublisher
{
    private string $gatewayUrl;
    private string $internalSecret;

    public function __construct()
    {
        $this->gatewayUrl = config('app.gateway_url', 'http://127.0.0.1:4000');
        $this->internalSecret = config('app.gateway_internal_secret', 'dev-secret-change-in-production');
    }

    /**
     * Publish Nagios config for a workspace
     */
    public function publish(int $workspaceId): void
    {
        // Check if tables exist (migration guard)
        if (!\Illuminate\Support\Facades\Schema::hasTable('observe_targets_hosts')) {
            throw new \Exception('Database tables not found. Please run migrations first: php artisan migrate');
        }
        
        $hosts = ObserveTargetHost::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->with(['services' => function ($query) {
                $query->where('enabled', true);
            }])
            ->get();

        if ($hosts->isEmpty()) {
            Log::info("No enabled targets for workspace {$workspaceId}, skipping config publish");
            return;
        }

        $config = $this->buildConfig($hosts, $workspaceId);

        // Write config via gateway
        $response = Http::timeout(30)
            ->withHeaders([
                'x-internal-secret' => $this->internalSecret,
                'x-workspace-id' => (string) $workspaceId,
                'Content-Type' => 'application/json',
            ])
            ->put("{$this->gatewayUrl}/internal/engines/nagios/config", [
                'config' => $config,
            ]);

        if (!$response->successful()) {
            throw new \Exception("Gateway returned {$response->status()}: {$response->body()}");
        }

        // Reload Nagios
        $reloadResponse = Http::timeout(30)
            ->withHeaders([
                'x-internal-secret' => $this->internalSecret,
            ])
            ->post("{$this->gatewayUrl}/internal/engines/nagios/reload");

        $reloadSuccess = $reloadResponse->successful();
        $reloadData = $reloadResponse->json();
        
        // Track publish status in observe_meta
        $publishSuccess = $reloadSuccess && 
                         isset($reloadData['validated']) && 
                         $reloadData['validated'] === true &&
                         isset($reloadData['reloaded']) && 
                         $reloadData['reloaded'] === true;
        
        $publishError = null;
        if (!$publishSuccess) {
            if (!$reloadSuccess) {
                $publishError = "Reload failed: HTTP {$reloadResponse->status()} - {$reloadResponse->body()}";
            } elseif (isset($reloadData['message'])) {
                $publishError = $reloadData['message'];
                if (isset($reloadData['stderr'])) {
                    $publishError .= ' - ' . substr($reloadData['stderr'], 0, 500);
                }
            } else {
                $publishError = 'Reload validation or execution failed';
            }
        }

        ObserveMeta::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'engine_key' => 'nagios',
            ],
            [
                'last_publish_at' => now(),
                'last_publish_success' => $publishSuccess,
                'last_publish_error' => $publishError,
            ]
        );

        if (!$reloadSuccess) {
            Log::warning("Failed to reload Nagios after config publish", [
                'workspace_id' => $workspaceId,
                'status' => $reloadResponse->status(),
                'body' => $reloadResponse->body(),
            ]);
            // Don't throw - config was written, reload can be done manually
        }
    }

    /**
     * Build Nagios config text from hosts and services
     */
    private function buildConfig($hosts, int $workspaceId): string
    {
        $lines = [
            '# PortShield Workspace ' . $workspaceId,
            '# DO NOT EDIT MANUALLY - This file is auto-generated',
            '',
        ];

        foreach ($hosts as $host) {
            // Host definition with workspace scoping prefix
            $scopedHostName = 'ws' . $workspaceId . '-' . $host->name;
            $lines[] = "define host {";
            $lines[] = "    use                     generic-host";
            $lines[] = "    host_name               {$scopedHostName}";
            $lines[] = "    address                 {$host->address}";
            $lines[] = "    check_command           {$host->check_command}";
            $lines[] = "    max_check_attempts      3";
            $lines[] = "    check_interval          5";
            $lines[] = "    retry_interval          1";
            $lines[] = "    notification_interval   60";
            $lines[] = "    notification_options    d,u,r";
            $lines[] = "    contact_groups          admins";
            $lines[] = "}";
            $lines[] = "";

            // Service definitions
            foreach ($host->services as $service) {
                $scopedHostName = 'ws' . $workspaceId . '-' . $host->name;
                $lines[] = "define service {";
                $lines[] = "    use                     generic-service";
                $lines[] = "    host_name               {$scopedHostName}";
                $lines[] = "    service_description     {$service->name}";
                
                // Build check command with args if provided
                $checkCmd = $service->check_command;
                if (!empty($service->check_args) && is_array($service->check_args)) {
                    $args = implode('!', array_map(function ($arg) {
                        // Escape for Nagios (remove dangerous chars, keep basic args)
                        return str_replace(['!', ';', "\n", "\r"], ['', '', '', ''], $arg);
                    }, $service->check_args));
                    if (!empty($args)) {
                        $checkCmd .= '!' . $args;
                    }
                }
                $lines[] = "    check_command           {$checkCmd}";
                
                $lines[] = "    max_check_attempts      3";
                $lines[] = "    check_interval          5";
                $lines[] = "    retry_interval          1";
                $lines[] = "    notification_interval   60";
                $lines[] = "    notification_options    w,u,c,r";
                $lines[] = "    contact_groups          admins";
                $lines[] = "}";
                $lines[] = "";
            }
        }

        return implode("\n", $lines);
    }
}

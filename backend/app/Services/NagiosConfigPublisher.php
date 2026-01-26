<?php

namespace App\Services;

use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $config = $this->buildConfig($hosts);

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

        if (!$reloadResponse->successful()) {
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
    private function buildConfig($hosts): string
    {
        $lines = [
            '# PortShield generated config',
            '# DO NOT EDIT MANUALLY - This file is auto-generated',
            '',
        ];

        foreach ($hosts as $host) {
            // Host definition
            $lines[] = "define host {";
            $lines[] = "    use                     generic-host";
            $lines[] = "    host_name               {$host->name}";
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
                $lines[] = "define service {";
                $lines[] = "    use                     generic-service";
                $lines[] = "    host_name               {$host->name}";
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

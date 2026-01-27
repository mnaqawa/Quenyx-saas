<?php

namespace App\Services;

use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\ObserveMeta;
use App\Models\ObserveServiceDefinition;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NagiosConfigPublisher
{
    private string $gatewayUrl;
    private string $internalSecret;
    private ObserveServiceCommandResolver $resolver;

    public function __construct(?ObserveServiceCommandResolver $resolver = null)
    {
        $this->gatewayUrl = config('app.gateway_url', 'http://127.0.0.1:4000');
        $this->internalSecret = config('app.gateway_internal_secret', 'dev-secret-change-in-production');
        $this->resolver = $resolver ?? app(ObserveServiceCommandResolver::class);
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
        $reloadData = $reloadResponse->json() ?? [];
        $reloadSkipped = !empty($reloadData['reload_skipped']);

        $publishSuccess = $reloadSuccess && (
            (isset($reloadData['reloaded']) && $reloadData['reloaded'] === true) ||
            $reloadSkipped
        );
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

        // After reload, call validate so we get a parsed list of errors/warnings (unknown check_command, duplicate object, etc.)
        $validateResponse = null;
        if ($reloadSuccess && !$reloadSkipped) {
            $validateResp = Http::timeout(30)
                ->withHeaders(['x-internal-secret' => $this->internalSecret])
                ->get("{$this->gatewayUrl}/internal/engines/nagios/validate");
            $validateResponse = $validateResp->json() ?? [];
            $valid = ($validateResponse['valid'] ?? false) === true;
            $validateErrors = $validateResponse['errors'] ?? [];
            if (!$valid || !empty($validateErrors)) {
                $publishSuccess = false;
                $msg = $validateResponse['message'] ?? 'Nagios config invalid';
                $errorsTrimmed = array_slice(is_array($validateErrors) ? $validateErrors : [], 0, 20);
                $publishError = $msg . (\count($errorsTrimmed) > 0 ? ': ' . implode('; ', $errorsTrimmed) : '');
            }
        }

        // Optionally assert ws{workspaceId}- hosts exist in Nagios hostlist after publish
        if ($publishSuccess && !$reloadSkipped) {
            $hostlistResp = Http::timeout(15)
                ->withHeaders(['x-internal-secret' => $this->internalSecret])
                ->get("{$this->gatewayUrl}/internal/engines/nagios/hostlist");
            if ($hostlistResp->successful()) {
                $hostlistData = $hostlistResp->json() ?? [];
                $hostlist = $hostlistData['data']['hostlist'] ?? [];
                $prefix = 'ws' . $workspaceId . '-';
                $hasWorkspaceHost = false;
                foreach (is_array($hostlist) ? $hostlist : [] as $h) {
                    if (is_string($h) && str_starts_with($h, $prefix)) {
                        $hasWorkspaceHost = true;
                        break;
                    }
                }
                if (!$hasWorkspaceHost) {
                    $publishSuccess = false;
                    $publishError = ($publishError ? $publishError . ' ' : '') . "No ws{$workspaceId}- hosts in Nagios hostlist.";
                }
            }
        }

        if ($publishSuccess && $reloadSkipped) {
            $publishError = null;
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

            // Service definitions: use resolver when a definition exists, else legacy
            foreach ($host->services as $service) {
                $scopedHostName = 'ws' . $workspaceId . '-' . $host->name;
                $lines[] = "define service {";
                $lines[] = "    use                     generic-service";
                $lines[] = "    host_name               {$scopedHostName}";
                $lines[] = "    service_description     {$service->name}";

                $checkCmd = $this->resolveServiceCheckCommand($service, $workspaceId);
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

    /**
     * Resolve service check_command via ObserveServiceCommandResolver when a definition exists.
     * Overrides come from check_args (keyed by arg key, or positional mapped to schema).
     */
    private function resolveServiceCheckCommand(ObserveTargetService $service, int $workspaceId): string
    {
        if (!Schema::hasTable('observe_service_definitions')) {
            return $this->legacyCheckCommand($service);
        }

        $definition = ObserveServiceDefinition::forEngine('nagios')
            ->where('check_command', $service->check_command)
            ->first();

        if ($definition === null) {
            $definition = ObserveServiceDefinition::forEngine('nagios')
                ->where('service_key', 'custom')
                ->first();
            if ($definition !== null) {
                $overrides = [
                    'command' => $service->check_command,
                    'args' => is_array($service->check_args ?? null) ? $service->check_args : [],
                ];
            } else {
                return $this->legacyCheckCommand($service);
            }
        } else {
            $raw = $service->check_args ?? [];
            $overrides = $this->buildOverridesFromArgs($definition, is_array($raw) ? $raw : []);
        }

        $context = [
            'workspace_id' => $workspaceId,
            'project_id' => $workspaceId,
            'has_custom_entitlement' => false,
        ];
        $result = $this->resolver->resolve($definition, $overrides, $context);

        if ($result->success) {
            return $result->check_command;
        }

        Log::warning('Observe service command resolve failed, using legacy', [
            'service_name' => $service->name,
            'check_command' => $service->check_command,
            'errors' => $result->errors,
        ]);
        return $this->legacyCheckCommand($service);
    }

    private function buildOverridesFromArgs(ObserveServiceDefinition $definition, array $args): array
    {
        $keys = array_column($definition->getOrderedArgsSchema(), 'key');
        if ($keys === []) {
            return $args;
        }
        $isAssoc = array_keys($args) !== range(0, count($args) - 1);
        if ($isAssoc) {
            return $args;
        }
        $overrides = [];
        foreach ($args as $i => $val) {
            if (isset($keys[$i])) {
                $overrides[$keys[$i]] = $val;
            }
        }
        return $overrides;
    }

    private function legacyCheckCommand(ObserveTargetService $service): string
    {
        $checkCmd = $service->check_command;
        if (!empty($service->check_args) && is_array($service->check_args)) {
            $args = implode('!', array_map(function ($arg) {
                return str_replace(['!', ';', "\n", "\r"], ['', '', '', ''], (string) $arg);
            }, $service->check_args));
            if ($args !== '') {
                $checkCmd .= '!' . $args;
            }
        }
        return $checkCmd;
    }
}

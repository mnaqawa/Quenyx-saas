<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\ObserveMeta;
use App\Models\ObserveServiceDefinition;
use Illuminate\Support\Facades\Cache;
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
     * Build default post-publish checks (all true = skipped or N/A).
     */
    private function defaultPostPublishChecks(): array
    {
        return [
            'cfg_includes_ok' => true,
            'validate_ok' => true,
            'reload_ok' => true,
            'objects_cache_contains_new_objects' => true,
        ];
    }

    /**
     * Publish Nagios config for a workspace (TPM: atomic, validated, reload-verified, locked, audited).
     * Post-publish verification: cfg included in nagios.cfg, nagios -v, reload, objects.cache contains new hosts.
     *
     * @param int $workspaceId
     * @param int|null $userId Optional; for audit trail.
     * @return array{nagios_publish_success: bool, nagios_publish_error: string|null, nagios_validation_errors: array, nagios_post_publish_checks: array}
     */
    public function publish(int $workspaceId, ?int $userId = null): array
    {
        $defaultChecks = $this->defaultPostPublishChecks();
        $emptyResult = [
            'nagios_publish_success' => true,
            'nagios_publish_error' => null,
            'nagios_validation_errors' => [],
            'nagios_post_publish_checks' => $defaultChecks,
        ];

        if (!Schema::hasTable('observe_targets_hosts')) {
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
            return $emptyResult;
        }

        $config = $this->buildConfig($hosts, $workspaceId);
        $lockSeconds = config('observe.publish_lock_seconds', 60);
        $lock = Cache::lock('nagios_publish', $lockSeconds);

        if (!$lock->block($lockSeconds)) {
            throw new \Exception('Could not acquire publish lock; another publish may be in progress.');
        }

        $result = 'success';
        $publishError = null;
        $validationErrors = [];
        $postChecks = [
            'cfg_includes_ok' => false,
            'validate_ok' => false,
            'reload_ok' => false,
            'objects_cache_contains_new_objects' => false,
        ];

        try {
            $writeResponse = Http::timeout(30)
                ->withHeaders([
                    'x-internal-secret' => $this->internalSecret,
                    'x-workspace-id' => (string) $workspaceId,
                    'Content-Type' => 'application/json',
                ])
                ->put("{$this->gatewayUrl}/internal/engines/nagios/config", [
                    'config' => $config,
                ]);

            if ($writeResponse->status() === 400) {
                $body = $writeResponse->json() ?? [];
                $validationErrors = $body['validation_errors'] ?? [];
                $msg = $body['message'] ?? 'Config validation failed';
                $publishError = $msg . (\count($validationErrors) > 0 ? ': ' . implode('; ', array_slice($validationErrors, 0, 5)) : '');
                $postChecks['validate_ok'] = false;
                if (\count($validationErrors) === 1
                    && str_contains($validationErrors[0] ?? '', 'Command failed: docker exec')
                    && str_contains($validationErrors[0] ?? '', 'nagios -v')) {
                    try {
                        $validateResp = Http::timeout(15)
                            ->withHeaders(['x-internal-secret' => $this->internalSecret])
                            ->get("{$this->gatewayUrl}/internal/engines/nagios/validate");
                        $validateBody = $validateResp->json() ?? [];
                        $validateErrors = $validateBody['errors'] ?? [];
                        Log::warning('Nagios config validation failed with generic gateway message. Current config validation result:', [
                            'workspace_id' => $workspaceId,
                            'validate_valid' => $validateBody['valid'] ?? null,
                            'validate_errors' => array_slice(is_array($validateErrors) ? $validateErrors : [], 0, 10),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Could not fetch Nagios validate after config failure', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);
                    }
                    Log::info('Restart the gateway service to get detailed Nagios validation output (Error: ... lines) in validation_errors.');
                }
                $this->auditPublish($workspaceId, $userId, 'failure', $publishError, $validationErrors);
                ObserveMeta::updateOrCreate(
                    ['workspace_id' => $workspaceId, 'engine_key' => 'nagios'],
                    ['last_publish_at' => now(), 'last_publish_success' => false, 'last_publish_error' => $publishError]
                );
                Log::info('Nagios post-publish checks (config write failed)', [
                    'workspace_id' => $workspaceId,
                    'nagios_post_publish_checks' => $postChecks,
                ]);
                return [
                    'nagios_publish_success' => false,
                    'nagios_publish_error' => $publishError,
                    'nagios_validation_errors' => $validationErrors,
                    'nagios_post_publish_checks' => $postChecks,
                ];
            }

            if (!$writeResponse->successful()) {
                $publishError = "Gateway returned {$writeResponse->status()}: {$writeResponse->body()}";
                $this->auditPublish($workspaceId, $userId, 'failure', $publishError);
                throw new \Exception($publishError);
            }

            $postChecks['validate_ok'] = true;

            // Verify generated workspace config is referenced by nagios.cfg
            $verifyIncludesResp = Http::timeout(15)
                ->withHeaders(['x-internal-secret' => $this->internalSecret])
                ->get("{$this->gatewayUrl}/internal/engines/nagios/verify-includes");
            $verifyIncludesData = $verifyIncludesResp->json() ?? [];
            $cfgIncludesOk = ($verifyIncludesData['ok'] ?? false) === true;
            $postChecks['cfg_includes_ok'] = $cfgIncludesOk;
            Log::info('Nagios post-publish check: cfg_includes_ok', [
                'workspace_id' => $workspaceId,
                'cfg_includes_ok' => $cfgIncludesOk,
                'message' => $verifyIncludesData['message'] ?? null,
            ]);
            if (!$cfgIncludesOk) {
                $publishError = $verifyIncludesData['message'] ?? 'Published config directory is not included in nagios.cfg (missing cfg_dir/cfg_file).';
                if (str_contains($publishError, 'cfg_dir') && !str_contains($publishError, 'Add cfg_dir')) {
                    $publishError .= ' Add cfg_dir=/opt/nagios/etc/objects/portshield/workspaces to the main nagios.cfg, or ensure the gateway has Docker access to auto-add it.';
                }
                $result = 'failure';
            }

            $reloadResponse = Http::timeout(30)
                ->withHeaders(['x-internal-secret' => $this->internalSecret])
                ->post("{$this->gatewayUrl}/internal/engines/nagios/reload");

            $reloadSuccess = $reloadResponse->successful();
            $reloadData = $reloadResponse->json() ?? [];
            $reloadSkipped = !empty($reloadData['reload_skipped']);
            $reloaded = (isset($reloadData['reloaded']) && $reloadData['reloaded'] === true) || $reloadSkipped;
            $postChecks['reload_ok'] = $reloadSuccess && $reloaded;

            Log::info('Nagios post-publish check: reload_ok', [
                'workspace_id' => $workspaceId,
                'reload_ok' => $postChecks['reload_ok'],
                'reload_skipped' => $reloadSkipped,
            ]);

            $publishSuccess = $cfgIncludesOk && $reloadSuccess && $reloaded;

            if (!$publishSuccess && !isset($publishError)) {
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
                $result = 'failure';
            }

            if ($reloadSuccess && !$reloadSkipped) {
                $validateResp = Http::timeout(30)
                    ->withHeaders(['x-internal-secret' => $this->internalSecret])
                    ->get("{$this->gatewayUrl}/internal/engines/nagios/validate");
                $validateResponse = $validateResp->json() ?? [];
                $valid = ($validateResponse['valid'] ?? false) === true;
                $validateErrors = $validateResponse['errors'] ?? [];
                if (!$valid || !empty($validateErrors)) {
                    $publishSuccess = false;
                    $result = 'failure';
                    $msg = $validateResponse['message'] ?? 'Nagios config invalid';
                    $errorsTrimmed = array_slice(is_array($validateErrors) ? $validateErrors : [], 0, 20);
                    $publishError = $msg . (\count($errorsTrimmed) > 0 ? ': ' . implode('; ', $errorsTrimmed) : '');
                }
            }

            // After reload: verify objects.cache contains workspace host name(s)
            if ($publishSuccess && $reloadSuccess && !$reloadSkipped) {
                $hostPrefix = 'ws' . $workspaceId . '-';
                $objectsCacheResp = Http::timeout(15)
                    ->withHeaders(['x-internal-secret' => $this->internalSecret])
                    ->get("{$this->gatewayUrl}/internal/engines/nagios/objects-cache-check", [
                        'host_prefix' => $hostPrefix,
                    ]);
                $objectsCacheData = $objectsCacheResp->json() ?? [];
                $containsNew = ($objectsCacheData['contains_new_objects'] ?? false) === true;
                $postChecks['objects_cache_contains_new_objects'] = $containsNew;
                Log::info('Nagios post-publish check: objects_cache_contains_new_objects', [
                    'workspace_id' => $workspaceId,
                    'host_prefix' => $hostPrefix,
                    'objects_cache_contains_new_objects' => $containsNew,
                    'message' => $objectsCacheData['message'] ?? null,
                ]);
                if (!$containsNew) {
                    $publishSuccess = false;
                    $result = 'failure';
                    $publishError = $objectsCacheData['message'] ?? 'Reload completed but objects.cache does not include newly published objects — config likely not loaded.';
                }
            } elseif ($reloadSkipped) {
                $postChecks['objects_cache_contains_new_objects'] = true;
            }

            if ($publishSuccess && $reloadSkipped) {
                $publishError = null;
            }

            ObserveMeta::updateOrCreate(
                ['workspace_id' => $workspaceId, 'engine_key' => 'nagios'],
                [
                    'last_publish_at' => now(),
                    'last_publish_success' => $publishSuccess,
                    'last_publish_error' => $publishError,
                ]
            );

            $this->auditPublish($workspaceId, $userId, $publishSuccess ? 'success' : 'failure', $publishError, $validationErrors);

            Log::info('Nagios post-publish checks (final)', [
                'workspace_id' => $workspaceId,
                'nagios_post_publish_checks' => $postChecks,
            ]);

            if (!$publishSuccess) {
                Log::warning('Publish completed with errors', [
                    'workspace_id' => $workspaceId,
                    'message' => $publishError,
                ]);
            }

            return [
                'nagios_publish_success' => $publishSuccess,
                'nagios_publish_error' => $publishError,
                'nagios_validation_errors' => $validationErrors,
                'nagios_post_publish_checks' => $postChecks,
            ];
        } finally {
            $lock->release();
        }
    }

    private function auditPublish(int $workspaceId, ?int $userId, string $result, ?string $error = null, array $validationErrors = []): void
    {
        Log::info('observe_nagios_publish', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'result' => $result,
            'error' => $error,
            'validation_errors' => array_slice($validationErrors, 0, 10),
        ]);
        if (class_exists(AuditLog::class) && Schema::hasTable('audit_logs')) {
            try {
                AuditLog::create([
                    'user_id' => $userId,
                    'project_id' => $workspaceId,
                    'action' => 'observe_nagios_publish',
                    'metadata' => [
                        'result' => $result,
                        'error' => $error,
                        'validation_errors' => array_slice($validationErrors, 0, 10),
                    ],
                    'timestamp' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to write publish audit log', ['message' => $e->getMessage()]);
            }
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

                $checkInterval = $service->check_interval ?? 5;
                $retryInterval = $service->retry_interval ?? 1;
                $lines[] = "    max_check_attempts      3";
                $lines[] = "    check_interval          " . max(1, (int) $checkInterval);
                $lines[] = "    retry_interval         " . max(1, (int) $retryInterval);
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
     * Prefer service_key for deterministic mapping (http→check_http, tcp_port→check_tcp, ping→check_ping).
     * Overrides come from check_args (keyed by arg key, or positional mapped to schema).
     * Do not use custom path when check_command is empty (avoids "Custom service denied" and invalid config).
     */
    private function resolveServiceCheckCommand(ObserveTargetService $service, int $workspaceId): string
    {
        $checkCommand = $service->check_command ?? '';
        if (!Schema::hasTable('observe_service_definitions')) {
            return $this->legacyCheckCommand($service);
        }

        // Prefer service_key so type is deterministic (avoids wrong command when check_command is missing/wrong)
        $serviceKey = $service->service_key ?? null;
        $definition = null;
        if ($serviceKey !== null && $serviceKey !== '') {
            $definition = ObserveServiceDefinition::forEngine('nagios')
                ->where('service_key', $serviceKey)
                ->first();
        }

        if ($definition === null && $checkCommand !== '') {
            // Fallback: match by base command (strip args after !)
            $baseCommand = strtolower(preg_replace('/!.*/', '', trim($checkCommand)));
            if ($baseCommand !== '') {
                $definition = ObserveServiceDefinition::forEngine('nagios')
                    ->where('check_command', $baseCommand)
                    ->first();
            }
            if ($definition === null) {
                $definition = ObserveServiceDefinition::forEngine('nagios')
                    ->where('check_command', trim($checkCommand))
                    ->first();
            }
        }

        if ($definition === null) {
            $definition = ObserveServiceDefinition::forEngine('nagios')
                ->where('service_key', 'custom')
                ->first();
            if ($definition !== null && $checkCommand !== '') {
                $overrides = [
                    'command' => $checkCommand,
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
        $checkCmd = $service->check_command ?? '';
        if ($checkCmd !== '') {
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
        // Fallback when check_command is empty (e.g. old/bad data) so Nagios config stays valid
        return 'check_ping!100.0,5%!500.0,20%!5';
    }
}

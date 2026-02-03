<?php

namespace App\Http\Controllers;

use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\ObserveService;
use App\Models\ObserveServiceDefinition;
use App\Models\Project;
use App\Services\NagiosConfigPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ObserveTargetsController extends Controller
{
    /**
     * Normalize overrides for JSON response: always return an object (associative array or stdClass).
     * Rejects list/empty array so frontend receives {} not [].
     *
     * @param mixed $check_args
     * @return array<string, mixed>|object
     */
    private function overridesForResponse($check_args)
    {
        if (! is_array($check_args)) {
            return (object) [];
        }
        if ($check_args === []) {
            return (object) [];
        }
        $keys = array_keys($check_args);
        if ($keys === range(0, count($check_args) - 1)) {
            return (object) [];
        }
        return $check_args;
    }

    /**
     * Normalize incoming overrides from request so we always persist a JSON object (associative array).
     * - null → []
     * - stdClass (JSON object) → associative array via json_decode(json_encode(...), true)
     * - array list (numeric keys) → [] (treat as "no overrides")
     * - associative array → preserved (never collapse to [])
     *
     * @param mixed $overrides
     * @return array<string, mixed>
     */
    private function normalizeIncomingOverrides($overrides): array
    {
        if ($overrides === null) {
            return [];
        }
        // Request middleware or gateway may leave nested JSON as string; decode once
        if (is_string($overrides)) {
            $decoded = json_decode($overrides, true);
            if (is_array($decoded)) {
                $overrides = $decoded;
            } else {
                return [];
            }
        }
        // JSON objects decoded without JSON_BIGINT_AS_STRING come as stdClass; convert to associative array
        if (is_object($overrides)) {
            $overrides = json_decode(json_encode($overrides), true);
            if (! is_array($overrides)) {
                return [];
            }
        }
        if (! is_array($overrides)) {
            return [];
        }
        $keys = array_keys($overrides);
        $n = count($overrides);
        // Clearly associative (string keys or non-sequential) → use as-is
        if ($n === 0) {
            return [];
        }
        if ($keys !== range(0, $n - 1)) {
            return $overrides;
        }
        // Numeric list: convert key-value shapes or return []
        $isList = true;
        if ($isList) {
            // Single pair [key, value] → associative
            if (count($overrides) === 2 && array_key_exists(0, $overrides) && array_key_exists(1, $overrides)) {
                return [ (string) $overrides[0] => $overrides[1] ];
            }
            // List of pairs [[k,v],[k,v],...] → associative
            $out = [];
            foreach ($overrides as $pair) {
                if (is_array($pair) && count($pair) === 2 && array_key_exists(0, $pair) && array_key_exists(1, $pair)) {
                    $out[(string) $pair[0]] = $pair[1];
                }
            }
            if ($out !== []) {
                return $out;
            }
            return [];
        }
        return $overrides;
    }

    /**
     * Resolve service_key for response: DB column when present, else infer from check_command/name.
     */
    private function serviceKeyForResponse(ObserveTargetService $service, array $definitionsByCommand): string
    {
        if (Schema::hasColumn($service->getTable(), 'service_key')) {
            $dbKey = $service->service_key;
            if ($dbKey !== null && $dbKey !== '') {
                return $dbKey;
            }
        }
        $raw = trim($service->check_command ?? '');
        $baseCommand = $raw !== '' ? strtolower(preg_replace('/!.*/', '', $raw)) : '';
        if ($baseCommand !== '' && isset($definitionsByCommand[$baseCommand])) {
            return $definitionsByCommand[$baseCommand];
        }
        return $this->inferServiceKeyFromServiceName($service->name ?? '') ?? '';
    }

    /**
     * Get observe targets for a workspace
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $definitionsByCommand = $this->definitionsByCheckCommand($project->id);
        $hosts = ObserveTargetHost::where('workspace_id', $project->id)
            ->with('services')
            ->orderBy('name')
            ->get()
            ->map(function ($host) use ($definitionsByCommand, $project) {
                return [
                    'id' => $host->id,
                    'name' => $host->name,
                    'address' => $host->address,
                    'check_command' => $host->check_command,
                    'tags' => $host->tags ?? [],
                    'enabled' => $host->enabled,
                    'services' => $host->services->map(function ($service) use ($definitionsByCommand, $project) {
                        $check_args = $service->check_args ?? [];
                        $responseOverrides = $this->overridesForResponse($check_args);
                        $service_key = $this->serviceKeyForResponse($service, $definitionsByCommand);
                        Log::debug('ObserveTargets GET overrides', [
                            'workspace_id' => $project->id,
                            'service_id' => $service->id,
                            'service_name' => $service->name,
                            'check_args_raw' => $check_args,
                            'response_overrides' => $responseOverrides,
                        ]);
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'service_key' => $service_key,
                            'check_command' => $service->check_command,
                            'check_args' => $check_args,
                            'overrides' => $responseOverrides,
                            'enabled' => $service->enabled,
                        ];
                    }),
                    'created_at' => $host->created_at->toIso8601String(),
                    'updated_at' => $host->updated_at->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $hosts,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Upsert observe targets (replace-style)
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        // Ensure hosts are read from JSON body when present (some proxies/server configs may not merge it)
        $hostsInput = $request->input('hosts');
        if ($hostsInput === null && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            $hostsInput = is_array($decoded['hosts'] ?? null) ? $decoded['hosts'] : [];
        }
        $hostsInput = is_array($hostsInput) ? $hostsInput : [];
        $request->merge(['hosts' => $hostsInput]);

        $validator = Validator::make($request->all(), [
            'hosts' => 'present|array',
            'hosts.*.name' => 'required|string|max:255',
            'hosts.*.address' => 'required|string|max:255',
            'hosts.*.check_command' => 'nullable|string|max:255',
            'hosts.*.tags' => 'nullable|array',
            'hosts.*.enabled' => 'nullable|boolean',
            'hosts.*.services' => 'nullable|array',
            'hosts.*.services.*.name' => 'required|string|max:255',
            'hosts.*.services.*.check_command' => 'nullable|string|max:255',
            'hosts.*.services.*.check_args' => 'nullable|array',
            'hosts.*.services.*.service_key' => 'nullable|string|max:100',
            'hosts.*.services.*.overrides' => 'nullable|array',
            'hosts.*.services.*.enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Allowed check commands (dev allowlist; used when service_key not provided)
        $allowedHostCommands = ['check-host-alive', 'check_ping'];
        $allowedServiceCommands = ['check_ping', 'check_http', 'check_load', 'check_users', 'check_disk', 'check_tcp'];

        // Sanitize and validate hosts; resolve service_key -> check_command + overrides -> check_args
        $hostsData = $request->input('hosts', []);
        $sanitizedHostNames = [];
        $errors = [];

        foreach ($hostsData as $index => &$hostData) {
            // Sanitize host name
            $originalName = $hostData['name'] ?? '';
            $sanitizedName = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', $originalName));
            
            if (empty($sanitizedName)) {
                $errors["hosts.{$index}.name"] = ['Host name must contain at least one alphanumeric character'];
                continue;
            }

            // Check uniqueness after sanitization
            if (isset($sanitizedHostNames[$sanitizedName])) {
                $errors["hosts.{$index}.name"] = ['Host name must be unique (after sanitization)'];
                continue;
            }
            $sanitizedHostNames[$sanitizedName] = true;

            // Validate check command
            $checkCommand = $hostData['check_command'] ?? 'check-host-alive';
            if (!in_array($checkCommand, $allowedHostCommands)) {
                $errors["hosts.{$index}.check_command"] = ['Invalid check command. Allowed: ' . implode(', ', $allowedHostCommands)];
            }

            // Resolve and validate services (service_key + overrides -> check_command + check_args)
            foreach ($hostData['services'] ?? [] as $serviceIndex => &$serviceData) {
                $originalServiceName = $serviceData['name'] ?? '';
                $sanitizedServiceName = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', $originalServiceName));
                
                if (empty($sanitizedServiceName)) {
                    $errors["hosts.{$index}.services.{$serviceIndex}.name"] = ['Service name must contain at least one alphanumeric character'];
                    continue;
                }

                // Always persist overrides as check_args (JSON object). Never allow numeric-array to overwrite.
                // Accept both 'overrides' and 'check_args' from request (some clients may send either).
                $rawOverrides = $serviceData['overrides'] ?? $serviceData['check_args'] ?? null;
                $overrides = $this->normalizeIncomingOverrides($rawOverrides);
                $serviceData['check_args'] = $overrides;

                $serviceKey = $serviceData['service_key'] ?? null;
                $incomingCheckCommand = $serviceData['check_command'] ?? null;

                // If service_key is provided, resolve it to check_command
                if ($serviceKey !== null && $serviceKey !== '' && Schema::hasTable('observe_service_definitions')) {
                    $def = ObserveServiceDefinition::forEngine('nagios')->where('service_key', $serviceKey)->first();
                    if ($def) {
                        $serviceData['check_command'] = $def->check_command;
                        // check_args already set once above; do not overwrite
                    } else {
                        $errors["hosts.{$index}.services.{$serviceIndex}.service_key"] = ['Unknown service_key: ' . $serviceKey];
                        if (!isset($serviceData['check_command'])) {
                            $serviceData['check_command'] = '';
                        }
                    }
                } else {
                    // Fallback: use check_command from request when service_key is empty (e.g. existing service from load)
                    if ($incomingCheckCommand !== null && $incomingCheckCommand !== '') {
                        $serviceData['check_command'] = $incomingCheckCommand;
                        // check_args already set from overrides above
                    } elseif (!isset($serviceData['check_command'])) {
                        $serviceData['check_command'] = '';
                    }
                }

                // Validate check_command if set
                $serviceCheckCommand = $serviceData['check_command'] ?? '';
                if (empty($serviceCheckCommand)) {
                    $errors["hosts.{$index}.services.{$serviceIndex}.check_command"] = ['Either service_key or check_command must be provided'];
                } elseif (!in_array($serviceCheckCommand, $allowedServiceCommands)) {
                    $errors["hosts.{$index}.services.{$serviceIndex}.check_command"] = ['Invalid check command. Allowed: ' . implode(', ', $allowedServiceCommands)];
                }
            }
            unset($serviceData);
        }
        unset($hostData);

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        // Apply sanitization to request data
        foreach ($hostsData as $index => &$hostData) {
            $hostData['name'] = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', $hostData['name']));
            foreach ($hostData['services'] ?? [] as $serviceIndex => &$serviceData) {
                $serviceData['name'] = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', $serviceData['name']));
            }
        }
        unset($hostData, $serviceData);

        try {
            DB::transaction(function () use ($project, $hostsData) {
                // Get existing host IDs to track what to delete
                $existingHostIds = ObserveTargetHost::where('workspace_id', $project->id)
                    ->pluck('id')
                    ->toArray();
                $newHostIds = [];

                foreach ($hostsData as $hostData) {
                    $host = ObserveTargetHost::updateOrCreate(
                        [
                            'workspace_id' => $project->id,
                            'name' => $hostData['name'],
                        ],
                        [
                            'address' => $hostData['address'],
                            'check_command' => $hostData['check_command'] ?? 'check-host-alive',
                            'tags' => $hostData['tags'] ?? [],
                            'enabled' => $hostData['enabled'] ?? true,
                        ]
                    );
                    
                    $newHostIds[] = $host->id;

                    // Handle services
                    $servicesData = $hostData['services'] ?? [];
                    $existingServiceIds = $host->services()->pluck('id')->toArray();
                    $newServiceIds = [];

                    foreach ($servicesData as $serviceData) {
                        // check_args was set in validation loop from normalized overrides; ensure we never store numeric array
                        $checkArgsToStore = $serviceData['check_args'] ?? [];
                        if (is_array($checkArgsToStore) && array_keys($checkArgsToStore) === range(0, count($checkArgsToStore) - 1)) {
                            $checkArgsToStore = [];
                        }
                        // When request sent empty overrides, preserve existing DB value so we don't wipe saved config
                        if ($checkArgsToStore === []) {
                            $existing = ObserveTargetService::where('host_id', $host->id)
                                ->where('name', $serviceData['name'])
                                ->first();
                            if ($existing && is_array($existing->check_args) && $existing->check_args !== []
                                && ! (array_keys($existing->check_args) === range(0, count($existing->check_args) - 1))) {
                                $checkArgsToStore = $existing->check_args;
                            }
                        }
                        $serviceKeyToStore = isset($serviceData['service_key']) && $serviceData['service_key'] !== '' ? $serviceData['service_key'] : null;

                        Log::debug('ObserveTargets PUT overrides (before save)', [
                            'workspace_id' => $project->id,
                            'service_name' => $serviceData['name'] ?? null,
                            'incoming_overrides' => $serviceData['overrides'] ?? null,
                            'normalized_check_args' => $checkArgsToStore,
                        ]);

                        // Build update data; only include service_key if column exists (remove after migration is run everywhere)
                        $serviceTable = (new ObserveTargetService)->getTable();
                        $updateData = [
                            'workspace_id' => $project->id,
                            'check_command' => $serviceData['check_command'] ?? '',
                            'check_args' => $checkArgsToStore,
                            'enabled' => $serviceData['enabled'] ?? true,
                        ];
                        if (Schema::hasColumn($serviceTable, 'service_key')) {
                            $updateData['service_key'] = $serviceKeyToStore;
                        }

                        $service = ObserveTargetService::updateOrCreate(
                            [
                                'host_id' => $host->id,
                                'name' => $serviceData['name'],
                            ],
                            $updateData
                        );

                        $afterSave = $service->fresh();
                        $storedRaw = $afterSave ? $afterSave->getRawOriginal('check_args') : null;
                        $storedDecoded = $afterSave ? ($afterSave->check_args ?? []) : [];

                        Log::debug('ObserveTargets PUT overrides (after save)', [
                            'workspace_id' => $project->id,
                            'service_id' => $service->id,
                            'service_name' => $service->name,
                            'db_check_args_raw' => $storedRaw,
                            'db_check_args_decoded' => $storedDecoded,
                        ]);

                        $newServiceIds[] = $service->id;
                    }

                    // Delete services that were removed
                    $servicesToDelete = array_diff($existingServiceIds, $newServiceIds);
                    if (!empty($servicesToDelete)) {
                        ObserveTargetService::whereIn('id', $servicesToDelete)->delete();
                    }
                }

                // Delete hosts that were removed
                $hostsToDelete = array_diff($existingHostIds, $newHostIds);
                if (!empty($hostsToDelete)) {
                    ObserveTargetService::whereIn('host_id', $hostsToDelete)->delete();
                    ObserveTargetHost::whereIn('id', $hostsToDelete)->delete();
                }
            });

            // Verify persistence when we had hosts to write (fail fast if DB didn't persist)
            if (count($hostsData) > 0) {
                $hostCount = ObserveTargetHost::where('workspace_id', $project->id)->count();
                $serviceCount = ObserveTargetService::where('workspace_id', $project->id)->count();
                if ($hostCount === 0) {
                    throw new \RuntimeException('Targets transaction committed but no rows in observe_targets_hosts. Check table name and DB connection.');
                }
            }

            // Sync targets → observe_services immediately so Services page updates without waiting for Nagios poll
            $this->syncTargetsToObserveServices($project);

            $nagiosPublishSuccess = true;
            $nagiosPublishError = null;
            $nagiosValidationErrors = [];

            // Auto-publish config to Nagios if enabled; capture result for UI (do not pretend success)
            $autoPublish = filter_var(env('OBSERVE_AUTO_PUBLISH_NAGIOS', 'true'), FILTER_VALIDATE_BOOLEAN);
            if ($autoPublish) {
                try {
                    $publisher = new NagiosConfigPublisher();
                    $publisher->publish($project->id, auth()->id());
                    Artisan::call('observe:poll', ['--workspace_id' => (string) $project->id]);
                } catch (\App\Exceptions\NagiosPublishException $e) {
                    $nagiosPublishSuccess = false;
                    $nagiosPublishError = $e->getMessage();
                    $nagiosValidationErrors = $e->validationErrors;
                    Log::warning('Failed to publish Nagios config after targets update', [
                        'workspace_id' => $project->id,
                        'error' => $nagiosPublishError,
                    ]);
                } catch (\Exception $e) {
                    $nagiosPublishSuccess = false;
                    $nagiosPublishError = $e->getMessage();
                    $nagiosValidationErrors = [];
                    Log::warning('Failed to publish Nagios config after targets update', [
                        'workspace_id' => $project->id,
                        'error' => $nagiosPublishError,
                    ]);
                }
            } else {
                Log::info('Auto-publish disabled, skipping Nagios config publish', ['workspace_id' => $project->id]);
            }

            // Return updated targets + publish result so frontend can show success/failure
            $definitionsByCommand = $this->definitionsByCheckCommand($project->id);
            $hosts = ObserveTargetHost::where('workspace_id', $project->id)
                ->with('services')
                ->orderBy('name')
                ->get()
                ->map(function ($host) use ($definitionsByCommand) {
                    return [
                        'id' => $host->id,
                        'name' => $host->name,
                        'address' => $host->address,
                        'check_command' => $host->check_command,
                        'tags' => $host->tags ?? [],
                        'enabled' => $host->enabled,
                        'services' => $host->services->map(function ($service) use ($definitionsByCommand) {
                            $check_args = $service->check_args ?? [];
                            $service_key = $this->serviceKeyForResponse($service, $definitionsByCommand);
                            return [
                                'id' => $service->id,
                                'name' => $service->name,
                                'service_key' => $service_key,
                                'check_command' => $service->check_command,
                                'check_args' => $check_args,
                                'overrides' => $this->overridesForResponse($check_args),
                                'enabled' => $service->enabled,
                            ];
                        }),
                        'created_at' => $host->created_at->toIso8601String(),
                        'updated_at' => $host->updated_at->toIso8601String(),
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => $nagiosPublishSuccess ? 'Targets saved and published to Nagios' : 'Targets saved; Nagios publish failed',
                'data' => [
                    'targets' => $hosts,
                    'nagios_publish_success' => $nagiosPublishSuccess,
                    'nagios_publish_error' => $nagiosPublishError,
                    'nagios_validation_errors' => $nagiosValidationErrors,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('ObserveTargetsController@update failed', [
                'workspace_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e->getMessage() ?: 'Failed to update targets';
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => config('app.debug') ? ['detail' => $e->getTraceAsString()] : null,
            ], 500);
        }
    }

    /**
     * Validate targets configuration payload
     */
    public function validateTargetsPayload(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validator = Validator::make($request->all(), [
            'hosts' => 'present|array',
            'hosts.*.name' => 'required|string|max:255',
            'hosts.*.address' => 'required|string|max:255',
            'hosts.*.check_command' => 'nullable|string|max:255',
            'hosts.*.services' => 'nullable|array',
            'hosts.*.services.*.name' => 'required|string|max:255',
            'hosts.*.services.*.check_command' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Additional validation: check for duplicate names
        $hostNames = [];
        $errors = [];

        foreach ($request->input('hosts', []) as $index => $host) {
            $name = $host['name'] ?? '';
            if (isset($hostNames[$name])) {
                $errors["hosts.{$index}.name"] = ['Host name must be unique'];
            }
            $hostNames[$name] = true;

            // Check service names within host
            $serviceNames = [];
            foreach ($host['services'] ?? [] as $serviceIndex => $service) {
                $serviceName = $service['name'] ?? '';
                if (isset($serviceNames[$serviceName])) {
                    $errors["hosts.{$index}.services.{$serviceIndex}.name"] = ['Service name must be unique within host'];
                }
                $serviceNames[$serviceName] = true;
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'errors' => $errors,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'valid' => true,
            'message' => 'Configuration is valid',
        ]);
    }

    /**
     * Sync current targets (hosts + services) into observe_services so the Services page
     * reflects add/remove/modify immediately without waiting for Nagios poll.
     */
    private function syncTargetsToObserveServices(Project $project): void
    {
        if (!Schema::hasTable('observe_services')) {
            return;
        }

        $workspaceId = $project->id;
        $prefix = 'ws' . $workspaceId . '-';
        $engineKey = 'nagios';

        $hosts = ObserveTargetHost::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->with(['services' => fn ($q) => $q->where('enabled', true)])
            ->get();

        $receivedKeys = [];

        foreach ($hosts as $host) {
            $scopedHostName = $prefix . $host->name;
            foreach ($host->services as $service) {
                $engineServiceKey = $scopedHostName . '::' . $service->name;
                $receivedKeys[] = $engineServiceKey;

                ObserveService::updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'engine_key' => $engineKey,
                        'engine_service_key' => $engineServiceKey,
                    ],
                    [
                        'host_name' => $scopedHostName,
                        'service_name' => $service->name,
                        'state' => 'pending',
                        'last_check_at' => null,
                        'duration_sec' => null,
                        'attempt' => null,
                        'output' => 'Pending first check',
                        'perfdata' => null,
                    ]
                );
            }
        }

        if (!empty($receivedKeys)) {
            ObserveService::where('workspace_id', $workspaceId)
                ->where('engine_key', $engineKey)
                ->whereNotIn('engine_service_key', $receivedKeys)
                ->delete();
        } else {
            ObserveService::where('workspace_id', $workspaceId)
                ->where('engine_key', $engineKey)
                ->delete();
        }
    }

    /**
     * Infer service_key from service name when check_command is empty (legacy/edge-case).
     *
     * @return string|null service_key or null
     */
    private function inferServiceKeyFromServiceName(string $name): ?string
    {
        $n = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        if ($n === '') {
            return null;
        }
        if (str_contains($n, 'http') && !str_contains($n, 'tcp') && !preg_match('/port\s*\d+/', $n)) {
            return 'http';
        }
        if (str_contains($n, 'tcp') || preg_match('/port\s*\d+/', $n) || str_contains($n, 'port 8080')) {
            return 'tcp_port';
        }
        if (str_contains($n, 'ping') || str_contains($n, 'live')) {
            return 'ping';
        }
        return null;
    }

    /**
     * Fallback map when observe_service_definitions is missing or not seeded.
     * Ensures service_key is always restored from check_command for known types.
     *
     * @return array<string, string> check_command (base) => service_key
     */
    private function fallbackDefinitionsByCheckCommand(): array
    {
        return [
            'check_ping' => 'ping',
            'check_http' => 'http',
            'check_tcp' => 'tcp_port',
        ];
    }

    /**
     * @return array<string, string> check_command => service_key
     */
    private function definitionsByCheckCommand(int $workspaceId): array
    {
        $fallback = $this->fallbackDefinitionsByCheckCommand();
        if (!Schema::hasTable('observe_service_definitions')) {
            return $fallback;
        }
        $fromDb = ObserveServiceDefinition::forEngine('nagios')
            ->get()
            ->mapWithKeys(fn ($d) => [strtolower(trim($d->check_command ?? '')) => $d->service_key])
            ->all();
        return array_merge($fallback, $fromDb);
    }
}

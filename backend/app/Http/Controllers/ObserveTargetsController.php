<?php

namespace App\Http\Controllers;

use App\Models\ObserveService;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use App\Services\DefaultMonitoringProfileService;
use App\Services\ObserveCheckArgsSecrets;
use App\Services\ObserveServiceKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Jobs\RunObserveChecksJob;
use Illuminate\Support\Facades\DB;
use App\Support\SafeLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Collection;

class ObserveTargetsController extends Controller
{
    /**
     * Normalize overrides for JSON response: return empty object or associative array so JSON encodes as {} or object.
     * Do NOT return stdClass that would encode as {"stdClass":[]}.
     *
     * @param mixed $check_args
     * @return array<string, mixed>|object
     */
    private function overridesForResponse($check_args): array|object
    {
        if ($check_args === null) {
            return (object) [];
        }
        if (is_string($check_args)) {
            $decoded = json_decode($check_args, true);
            if (! is_array($decoded)) {
                return (object) [];
            }
            $check_args = $decoded;
        }
        if (! is_array($check_args)) {
            return (object) [];
        }
        if ($check_args === []) {
            return (object) [];
        }
        if (function_exists('array_is_list') && array_is_list($check_args)) {
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
     * - Collection → ->all()
     * - object → get_object_vars() (no json_encode/decode)
     * - array list → [] (no overrides)
     * - associative array → as-is
     *
     * @param mixed $overrides
     * @return array<string, mixed>
     */
    private function normalizeIncomingOverrides($overrides): array
    {
        if ($overrides === null) {
            return [];
        }
        if (is_string($overrides)) {
            $decoded = json_decode($overrides, true);
            if (is_array($decoded)) {
                $overrides = $decoded;
            } else {
                return [];
            }
        }
        if ($overrides instanceof Collection) {
            $overrides = $overrides->all();
        }
        if (is_object($overrides)) {
            $overrides = get_object_vars($overrides);
        }
        if (! is_array($overrides)) {
            return [];
        }
        if (function_exists('array_is_list') && array_is_list($overrides)) {
            return [];
        }
        $keys = array_keys($overrides);
        if ($keys === range(0, count($overrides) - 1)) {
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

        return app(ObserveServiceKeyResolver::class)->resolve(
            '',
            (string) ($service->check_command ?? ''),
            (string) ($service->name ?? '')
        );
    }

    /**
     * Get observe targets for a workspace
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $lifecycleFilter = $request->query('lifecycle', 'default');
        $definitionsByCommand = $this->definitionsByCheckCommand($project->id);

        $hostQuery = ObserveTargetHost::where('workspace_id', $project->id)
            ->with(['services' => fn ($q) => $q->orderBy('name')]);

        if ($lifecycleFilter === 'archived') {
            $hostQuery->where('lifecycle_status', \App\Constants\HostLifecycleStatus::ARCHIVED);
        } elseif ($lifecycleFilter === 'suspended') {
            $hostQuery->where('lifecycle_status', \App\Constants\HostLifecycleStatus::SUSPENDED);
        } elseif ($lifecycleFilter === 'agent_removed') {
            $hostQuery->where('lifecycle_status', \App\Constants\HostLifecycleStatus::AGENT_REMOVED);
        } elseif ($lifecycleFilter === 'all') {
            $hostQuery->visibleInList();
        } else {
            $hostQuery->visibleInList()
                ->where(function ($q) {
                    $q->whereIn('lifecycle_status', \App\Constants\HostLifecycleStatus::defaultListFilter())
                        ->orWhereNull('lifecycle_status');
                });
        }

        $hostRows = $hostQuery->orderBy('name')->get();

        if ($hostRows->isEmpty()) {
            $this->backfillTargetsFromNativeServices($project);
            $hostQuery = ObserveTargetHost::where('workspace_id', $project->id)
                ->with(['services' => fn ($q) => $q->orderBy('name')]);
            if ($lifecycleFilter === 'archived') {
                $hostQuery->where('lifecycle_status', \App\Constants\HostLifecycleStatus::ARCHIVED);
            } elseif ($lifecycleFilter === 'suspended') {
                $hostQuery->where('lifecycle_status', \App\Constants\HostLifecycleStatus::SUSPENDED);
            } elseif ($lifecycleFilter === 'agent_removed') {
                $hostQuery->where('lifecycle_status', \App\Constants\HostLifecycleStatus::AGENT_REMOVED);
            } elseif ($lifecycleFilter === 'all') {
                $hostQuery->visibleInList();
            } else {
                $hostQuery->visibleInList()
                    ->where(function ($q) {
                        $q->whereIn('lifecycle_status', \App\Constants\HostLifecycleStatus::defaultListFilter())
                            ->orWhereNull('lifecycle_status');
                    });
            }
            $hostRows = $hostQuery->orderBy('name')->get();
        }

        $hosts = $hostRows->map(function ($host) use ($definitionsByCommand, $project) {
                return [
                    'id' => $host->id,
                    'name' => $host->name,
                    'address' => $host->address,
                    'public_ip' => $host->public_ip ?? null,
                    'check_command' => $host->check_command,
                    'tags' => $host->tags ?? [],
                    'enabled' => $host->enabled,
                    'lifecycle_status' => $host->lifecycle_status ?? 'active',
                    'lifecycle_reason' => $host->lifecycle_reason,
                    'lifecycle_changed_at' => $host->lifecycle_changed_at?->toIso8601String(),
                    'uuid' => $host->uuid,
                    'agent_id' => $host->agent_id,
                    'source' => $host->source,
                    'services' => $host->services->map(function ($service) use ($definitionsByCommand, $project) {
                        $check_args = is_array($service->check_args) ? $service->check_args : [];
                        $service_key = $this->serviceKeyForResponse($service, $definitionsByCommand);
                        $definition = $service_key !== ''
                            ? ObserveServiceDefinition::where('service_key', $service_key)->first()
                            : null;
                        $secrets = app(ObserveCheckArgsSecrets::class);
                        $responseOverrides = $this->overridesForResponse(
                            $secrets->redactForResponse($check_args, $definition)
                        );
                        $configuredSecrets = $secrets->configuredSecretKeys($check_args, $definition);
                        if (config('app.debug')) {
                            SafeLog::debug('ObserveTargets GET overrides', [
                                'workspace_id' => $project->id,
                                'service_id' => $service->id,
                                'service_name' => $service->name,
                                'service_key' => $service_key,
                                'check_command' => $service->check_command,
                            ]);
                        }
                        $row = [
                            'id' => $service->id,
                            'name' => $service->name,
                            'service_key' => $service_key,
                            'check_command' => $service->check_command,
                            'check_args' => $secrets->redactForResponse($check_args, $definition),
                            'overrides' => $responseOverrides,
                            'configured_secrets' => $configuredSecrets,
                            'enabled' => $service->enabled,
                        ];
                        if (Schema::hasColumn($service->getTable(), 'check_interval')) {
                            $row['check_interval'] = $service->check_interval;
                        }
                        if (Schema::hasColumn($service->getTable(), 'retry_interval')) {
                            $row['retry_interval'] = $service->retry_interval;
                        }
                        return $row;
                    }),
                    'created_at' => $host->created_at->toIso8601String(),
                    'updated_at' => $host->updated_at->toIso8601String(),
                ];
            });

        $logKey = 'observe_targets_list_log_' . $project->id . '_' . ($request->user()?->id ?? 'guest');
        if (! Cache::has($logKey)) {
            SafeLog::info('Observe targets listed', [
                'workspace_id' => $project->id,
                'host_count' => $hosts->count(),
                'user_id' => $request->user()?->id,
            ]);
            Cache::put($logKey, true, now()->addSeconds(15));
        }

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
            'hosts.*.public_ip' => 'nullable|string|max:45',
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
            'hosts.*.services.*.check_interval' => 'nullable|integer|min:1|max:86400',
            'hosts.*.services.*.retry_interval' => 'nullable|integer|min:1|max:86400',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allowedHostCommands = ['check-host-alive', 'check_ping'];
        $allowedServiceCommands = $this->getAllowedServiceCheckCommands();

        // Sanitize and validate hosts; resolve service_key -> check_command + overrides -> check_args
        $hostsData = $request->input('hosts', []);
        $existingHostCount = ObserveTargetHost::where('workspace_id', $project->id)->count();
        if ($existingHostCount > 0 && count($hostsData) === 0 && ! $request->boolean('confirm_empty')) {
            SafeLog::warning('Observe targets save rejected: empty host list would remove configured targets', [
                'workspace_id' => $project->id,
                'existing_hosts' => $existingHostCount,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Saving an empty host list would remove all configured hosts. Re-add your hosts or send confirm_empty=true to proceed intentionally.',
                'code' => 'empty_targets_blocked',
                'existing_host_count' => $existingHostCount,
            ], 409);
        }

        $sanitizedHostNames = [];
        $errors = [];

        // Single source of truth for check_args: computed once here, used at save. Key: hostIndex => [ serviceIndex => normalized array ]
        $normalizedCheckArgsByIndex = [];

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
            $normalizedCheckArgsByIndex[$index] = [];
            foreach ($hostData['services'] ?? [] as $serviceIndex => &$serviceData) {
                $originalServiceName = $serviceData['name'] ?? '';
                $sanitizedServiceName = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', $originalServiceName));
                
                if (empty($sanitizedServiceName)) {
                    $errors["hosts.{$index}.services.{$serviceIndex}.name"] = ['Service name must contain at least one alphanumeric character'];
                    continue;
                }

                // Compute normalized check_args ONCE; store in our map and in $serviceData (for any code that reads it).
                $rawOverrides = $serviceData['overrides'] ?? $serviceData['check_args'] ?? null;
                $normalizedCheckArgs = $this->normalizeIncomingOverrides($rawOverrides);
                $normalizedCheckArgsByIndex[$index][$serviceIndex] = $normalizedCheckArgs;
                $serviceData['check_args'] = $normalizedCheckArgs;

                if (config('app.debug')) {
                    SafeLog::debug('ObserveTargets PUT check_args assigned (validation loop)', [
                        'workspace_id' => $project->id,
                        'service_name' => $serviceData['name'] ?? null,
                        'service_key' => $serviceKey ?? null,
                        'check_command' => $serviceData['check_command'] ?? null,
                    ]);
                }

                $serviceKey = $serviceData['service_key'] ?? null;
                $incomingCheckCommand = $serviceData['check_command'] ?? null;

                // If service_key is provided, resolve it to check_command
                if ($serviceKey !== null && $serviceKey !== '' && Schema::hasTable('observe_service_definitions')) {
                    $def = ObserveServiceDefinition::where('service_key', $serviceKey)->first();
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

        $savedHostIds = [];
        try {
            DB::transaction(function () use ($project, $hostsData, $normalizedCheckArgsByIndex, &$savedHostIds) {
                // Get existing host IDs to track what to delete
                $existingHostIds = ObserveTargetHost::where('workspace_id', $project->id)
                    ->pluck('id')
                    ->toArray();
                $newHostIds = [];

                foreach ($hostsData as $hostIndex => $hostData) {
                    $host = ObserveTargetHost::updateOrCreate(
                        [
                            'workspace_id' => $project->id,
                            'name' => $hostData['name'],
                        ],
                        [
                            'address' => $hostData['address'],
                            'public_ip' => $hostData['public_ip'] ?? null,
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

                    foreach ($servicesData as $serviceIndex => $serviceData) {
                        // Single source of truth: use normalized value from validation loop; do not read from $serviceData (can be lost in closure).
                        $checkArgsToStore = $normalizedCheckArgsByIndex[$hostIndex][$serviceIndex] ?? [];
                        $existing = ObserveTargetService::where('host_id', $host->id)
                            ->where('name', $serviceData['name'])
                            ->first();
                        $serviceKeyToStore = isset($serviceData['service_key']) && $serviceData['service_key'] !== '' ? $serviceData['service_key'] : null;
                        $definition = $serviceKeyToStore
                            ? ObserveServiceDefinition::where('service_key', $serviceKeyToStore)->first()
                            : null;
                        $existingArgs = $existing && is_array($existing->check_args) ? $existing->check_args : null;
                        $checkArgsToStore = app(ObserveCheckArgsSecrets::class)->mergePreservedSecrets(
                            $checkArgsToStore,
                            $existingArgs,
                            $definition
                        );
                        // When request sent empty overrides, preserve existing DB value so we don't wipe saved config
                        if ($checkArgsToStore === [] && $existingArgs !== null && $existingArgs !== []) {
                            $checkArgsToStore = $existingArgs;
                        }

                        if (config('app.debug')) {
                            SafeLog::debug('ObserveTargets PUT overrides (before save)', [
                                'workspace_id' => $project->id,
                                'service_name' => $serviceData['name'] ?? null,
                                'service_key_to_store' => $serviceKeyToStore,
                                'check_command' => $serviceData['check_command'] ?? null,
                            ]);
                        }

                        // Build update data; only include service_key if column exists (remove after migration is run everywhere)
                        $serviceTable = (new ObserveTargetService)->getTable();
                        $updateData = [
                            'workspace_id' => $project->id,
                            'check_command' => $serviceData['check_command'] ?? '',
                            'check_args' => $checkArgsToStore,
                            'enabled' => $serviceData['enabled'] ?? true,
                        ];
                        if (Schema::hasColumn($serviceTable, 'check_interval')) {
                            $updateData['check_interval'] = isset($serviceData['check_interval']) ? (int) $serviceData['check_interval'] : null;
                        }
                        if (Schema::hasColumn($serviceTable, 'retry_interval')) {
                            $updateData['retry_interval'] = isset($serviceData['retry_interval']) ? (int) $serviceData['retry_interval'] : null;
                        }
                        if (config('app.debug')) {
                            SafeLog::debug('ObserveTargets PUT (before save)', [
                                'workspace_id' => $project->id,
                                'service_name' => $serviceData['name'] ?? null,
                                'service_key' => $serviceKeyToStore,
                                'check_command' => $serviceData['check_command'] ?? null,
                            ]);
                        }
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

                        if (config('app.debug')) {
                            SafeLog::debug('ObserveTargets PUT overrides (after save)', [
                                'workspace_id' => $project->id,
                                'service_id' => $service->id,
                                'service_name' => $service->name,
                                'service_key' => $service->service_key ?? null,
                                'check_command' => $service->check_command ?? null,
                            ]);
                        }

                        $newServiceIds[] = $service->id;
                    }

                    // Delete services that were removed
                    $servicesToDelete = array_diff($existingServiceIds, $newServiceIds);
                    if (!empty($servicesToDelete)) {
                        ObserveTargetService::whereIn('id', $servicesToDelete)->delete();
                    }

                    app(DefaultMonitoringProfileService::class)->attachToHost($host, $project->id);
                }

                // Delete hosts that were removed
                $hostsToDelete = array_diff($existingHostIds, $newHostIds);
                if (!empty($hostsToDelete)) {
                    ObserveTargetService::whereIn('host_id', $hostsToDelete)->delete();
                    ObserveTargetHost::whereIn('id', $hostsToDelete)->delete();
                }
                $savedHostIds = $newHostIds;
            });

            // Verify persistence when we had hosts to write (fail fast if DB didn't persist)
            if (count($hostsData) > 0) {
                $hostCount = ObserveTargetHost::where('workspace_id', $project->id)->count();
                $serviceCount = ObserveTargetService::where('workspace_id', $project->id)->count();
                if ($hostCount === 0) {
                    throw new \RuntimeException('Targets transaction committed but no rows in observe_targets_hosts. Check table name and DB connection.');
                }
            }

            // Sync targets → observe_services (native engine) so Services page shows targets; run-checks will update state
            $this->syncTargetsToObserveServices($project);

            // Run nmap port scans for each host saved (direct call avoids queue config issues; runs in request)
            $nmapService = app(\App\Services\NmapPortScanService::class);
            foreach ($savedHostIds as $hostId) {
                try {
                    $host = ObserveTargetHost::find($hostId);
                    if ($host) {
                        $nmapService->runScan($host);
                    }
                } catch (\Throwable $e) {
                    SafeLog::warning('Nmap port scan failed', ['host_id' => $hostId, 'error' => $e->getMessage()]);
                }
            }

            // Run native checks once for this workspace so Services page updates immediately
            RunObserveChecksJob::dispatch($project->id)->afterResponse();

            // Return updated targets (no Nagios publish; we use QynSight native engine only)
            $definitionsByCommand = $this->definitionsByCheckCommand($project->id);
            $hosts = ObserveTargetHost::where('workspace_id', $project->id)
                ->with(['services' => fn ($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(function ($host) use ($definitionsByCommand) {
                    return [
                        'id' => $host->id,
                        'name' => $host->name,
                        'address' => $host->address,
                        'public_ip' => $host->public_ip ?? null,
                        'check_command' => $host->check_command,
                        'tags' => $host->tags ?? [],
                        'enabled' => $host->enabled,
                        'services' => $host->services->map(function ($service) use ($definitionsByCommand) {
                            $check_args = is_array($service->check_args) ? $service->check_args : [];
                            $service_key = $this->serviceKeyForResponse($service, $definitionsByCommand);
                            $definition = $service_key !== ''
                                ? ObserveServiceDefinition::where('service_key', $service_key)->first()
                                : null;
                            $secrets = app(ObserveCheckArgsSecrets::class);
                            $row = [
                                'id' => $service->id,
                                'name' => $service->name,
                                'service_key' => $service_key,
                                'check_command' => $service->check_command,
                                'check_args' => $secrets->redactForResponse($check_args, $definition),
                                'overrides' => $this->overridesForResponse(
                                    $secrets->redactForResponse($check_args, $definition)
                                ),
                                'configured_secrets' => $secrets->configuredSecretKeys($check_args, $definition),
                                'enabled' => $service->enabled,
                            ];
                            if (Schema::hasColumn($service->getTable(), 'check_interval')) {
                                $row['check_interval'] = $service->check_interval;
                            }
                            if (Schema::hasColumn($service->getTable(), 'retry_interval')) {
                                $row['retry_interval'] = $service->retry_interval;
                            }
                            return $row;
                        }),
                        'created_at' => $host->created_at->toIso8601String(),
                        'updated_at' => $host->updated_at->toIso8601String(),
                    ];
                });

            SafeLog::info('Observe targets saved', [
                'workspace_id' => $project->id,
                'host_count' => $hosts->count(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Targets saved. Checks run by QynSight engine.',
                'data' => [
                    'targets' => $hosts,
                ],
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');

        } catch (\Exception $e) {
            SafeLog::error('ObserveTargetsController@update failed', [
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
     * Get nmap port scan results for a host.
     */
    public function portScan(Request $request, Project $project, int $hostId): JsonResponse
    {
        $this->authorize('view', $project);

        $host = ObserveTargetHost::where('workspace_id', $project->id)->where('id', $hostId)->first();
        if (!$host) {
            return response()->json(['success' => false, 'message' => 'Host not found'], 404);
        }

        $latestScan = $host->portScans()->with('results')->orderByDesc('id')->first();
        if (!$latestScan) {
            return response()->json([
                'success' => true,
                'data' => [
                    'host_id' => $host->id,
                    'host_name' => $host->name,
                    'address' => $host->address,
                    'scan' => null,
                    'ports' => [],
                ],
            ]);
        }

        $ports = $latestScan->results->map(fn ($r) => [
            'port' => $r->port,
            'protocol' => $r->protocol,
            'state' => $r->state,
            'service' => $r->service,
            'version' => $r->version,
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'host_id' => $host->id,
                'host_name' => $host->name,
                'address' => $host->address,
                'scan' => [
                    'id' => $latestScan->id,
                    'status' => $latestScan->status,
                    'scanned_at' => $latestScan->scanned_at?->toIso8601String(),
                    'open_ports_count' => $latestScan->open_ports_count,
                    'error_message' => $latestScan->error_message,
                ],
                'ports' => $ports,
            ],
        ]);
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
            'hosts.*.public_ip' => 'nullable|string|max:45',
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
     * reflects them. Uses native engine; observe:run-checks will run checks and update state.
     */
    private function syncTargetsToObserveServices(Project $project): void
    {
        if (!Schema::hasTable('observe_services')) {
            return;
        }

        $workspaceId = $project->id;
        $prefix = 'ws' . $workspaceId . '-';
        $engineKey = 'native';

        $hosts = ObserveTargetHost::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->with(['services' => fn ($q) => $q->where('enabled', true)])
            ->get();

        $receivedKeys = [];

        foreach ($hosts as $host) {
            $scopedHostName = $prefix . $host->name;
            $hasServices = false;
            foreach ($host->services as $service) {
                $hasServices = true;
                $engineServiceKey = $scopedHostName . '::' . $service->name;
                $receivedKeys[] = $engineServiceKey;

                $record = ObserveService::firstOrCreate(
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
                        'output' => 'Pending first check (QynSight)',
                        'perfdata' => null,
                    ]
                );

                if (! $record->wasRecentlyCreated) {
                    $record->update([
                        'host_name' => $scopedHostName,
                        'service_name' => $service->name,
                    ]);
                }
            }
            // Hosts with no services get a synthetic Host-Alive (ping) so they show real status instead of "Pending"
            if (! $hasServices) {
                $engineServiceKey = $scopedHostName . '::Host-Alive';
                $receivedKeys[] = $engineServiceKey;

                $alive = ObserveService::firstOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'engine_key' => $engineKey,
                        'engine_service_key' => $engineServiceKey,
                    ],
                    [
                        'host_name' => $scopedHostName,
                        'service_name' => 'Host-Alive',
                        'state' => 'pending',
                        'last_check_at' => null,
                        'duration_sec' => null,
                        'attempt' => null,
                        'output' => 'Pending first check (QynSight)',
                        'perfdata' => null,
                    ]
                );

                if (! $alive->wasRecentlyCreated) {
                    $alive->update([
                        'host_name' => $scopedHostName,
                        'service_name' => 'Host-Alive',
                    ]);
                }
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
            'check_mysql' => 'mysql',
            'check_pgsql' => 'pgsql',
            'check_ssl_validity' => 'ssl_validity',
        ];
    }

    /**
     * Allowed check_command values for validation (from definitions + base).
     *
     * @return array<int, string>
     */
    private function getAllowedServiceCheckCommands(): array
    {
        $base = ['check_ping', 'check_http', 'check_tcp', 'check_plugin', 'check_disk', 'check_load', 'check_swap', 'check_users', 'check_cpu', 'check_memory', 'check_inodes', 'check_uptime', 'check_procs', 'check_ntp_time', 'check_ssh', 'check_dns', 'check_smtp', 'check_mysql', 'check_pgsql', 'check_ssl_validity'];
        if (!Schema::hasTable('observe_service_definitions')) {
            return $base;
        }
        $fromDb = ObserveServiceDefinition::query()
            ->get()
            ->pluck('check_command')
            ->filter(fn ($c) => is_string($c) && $c !== '')
            ->map(fn ($c) => strtolower(trim($c)))
            ->unique()
            ->values()
            ->all();
        return array_values(array_unique(array_merge($base, $fromDb)));
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
        $fromDb = ObserveServiceDefinition::query()
            ->get()
            ->mapWithKeys(fn ($d) => [strtolower(trim($d->check_command ?? '')) => $d->service_key])
            ->all();
        return array_merge($fallback, $fromDb);
    }

    /**
     * When observe_targets_hosts is empty but native observe_services has rows, recreate target hosts
     * so the Hosts UI and workspace cards show configured monitoring (legacy/migration recovery).
     */
    private function backfillTargetsFromNativeServices(Project $project): int
    {
        if (! Schema::hasTable('observe_services')) {
            return 0;
        }

        if (ObserveTargetHost::where('workspace_id', $project->id)->exists()) {
            return 0;
        }

        $prefix = 'ws' . $project->id . '-';
        $rows = ObserveService::where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $prefix . '%')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $created = 0;
        $allowedCommands = $this->getAllowedServiceCheckCommands();

        DB::transaction(function () use ($project, $prefix, $rows, &$created, $allowedCommands) {
            foreach ($rows->groupBy('host_name') as $scopedHostName => $services) {
                $shortName = str_starts_with((string) $scopedHostName, $prefix)
                    ? substr((string) $scopedHostName, strlen($prefix))
                    : (string) $scopedHostName;
                if ($shortName === '') {
                    continue;
                }

                $host = ObserveTargetHost::create([
                    'workspace_id' => $project->id,
                    'name' => $shortName,
                    'address' => $shortName,
                    'check_command' => 'check-host-alive',
                    'tags' => ['backfill' => 'observe_services'],
                    'enabled' => true,
                    'source' => 'backfill',
                ]);

                foreach ($services->unique('service_name') as $service) {
                    $serviceName = (string) $service->service_name;
                    if ($serviceName === '' || strcasecmp($serviceName, 'Host-Alive') === 0) {
                        continue;
                    }
                    $checkCommand = trim((string) ($service->check_command ?? ''));
                    if ($checkCommand === '' || ! in_array($checkCommand, $allowedCommands, true)) {
                        $checkCommand = 'check_ping';
                    }

                    ObserveTargetService::create([
                        'workspace_id' => $project->id,
                        'host_id' => $host->id,
                        'name' => $serviceName,
                        'check_command' => $checkCommand,
                        'check_args' => [],
                        'enabled' => true,
                    ]);
                }

                app(DefaultMonitoringProfileService::class)->attachToHost($host, $project->id);
                $created++;
            }
        });

        if ($created > 0) {
            SafeLog::info('Observe targets backfilled from native services', [
                'workspace_id' => $project->id,
                'host_count' => $created,
            ]);
        }

        return $created;
    }
}

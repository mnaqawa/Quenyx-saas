<?php

namespace App\Http\Controllers;

use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use App\Services\NagiosConfigPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ObserveTargetsController extends Controller
{
    /**
     * Get observe targets for a workspace
     */
    public function index(Request $request, Project $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $hosts = ObserveTargetHost::where('workspace_id', $workspace->id)
            ->with('services')
            ->orderBy('name')
            ->get()
            ->map(function ($host) {
                return [
                    'id' => $host->id,
                    'name' => $host->name,
                    'address' => $host->address,
                    'check_command' => $host->check_command,
                    'tags' => $host->tags ?? [],
                    'enabled' => $host->enabled,
                    'services' => $host->services->map(function ($service) {
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'check_command' => $service->check_command,
                            'check_args' => $service->check_args ?? [],
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
        ]);
    }

    /**
     * Upsert observe targets (replace-style)
     */
    public function update(Request $request, Project $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        $validator = Validator::make($request->all(), [
            'hosts' => 'required|array',
            'hosts.*.name' => 'required|string|max:255',
            'hosts.*.address' => 'required|string|max:255',
            'hosts.*.check_command' => 'nullable|string|max:255',
            'hosts.*.tags' => 'nullable|array',
            'hosts.*.enabled' => 'nullable|boolean',
            'hosts.*.services' => 'nullable|array',
            'hosts.*.services.*.name' => 'required|string|max:255',
            'hosts.*.services.*.check_command' => 'required|string|max:255',
            'hosts.*.services.*.check_args' => 'nullable|array',
            'hosts.*.services.*.enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($workspace, $request) {
                $hostsData = $request->input('hosts', []);
                
                // Get existing host IDs to track what to delete
                $existingHostIds = ObserveTargetHost::where('workspace_id', $workspace->id)
                    ->pluck('id')
                    ->toArray();
                $newHostIds = [];

                foreach ($hostsData as $hostData) {
                    $host = ObserveTargetHost::updateOrCreate(
                        [
                            'workspace_id' => $workspace->id,
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
                        $service = ObserveTargetService::updateOrCreate(
                            [
                                'host_id' => $host->id,
                                'name' => $serviceData['name'],
                            ],
                            [
                                'workspace_id' => $workspace->id,
                                'check_command' => $serviceData['check_command'],
                                'check_args' => $serviceData['check_args'] ?? [],
                                'enabled' => $serviceData['enabled'] ?? true,
                            ]
                        );
                        
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

            // Publish config to Nagios
            try {
                $publisher = new NagiosConfigPublisher();
                $publisher->publish($workspace->id);
            } catch (\Exception $e) {
                Log::warning('Failed to publish Nagios config after targets update', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the request, just log
            }

            return response()->json([
                'success' => true,
                'message' => 'Targets updated and published to Nagios',
            ]);

        } catch (\Exception $e) {
            Log::error('ObserveTargetsController@update failed', [
                'workspace_id' => $workspace->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update targets',
            ], 500);
        }
    }

    /**
     * Validate targets configuration
     */
    public function validate(Request $request, Project $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $validator = Validator::make($request->all(), [
            'hosts' => 'required|array',
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
}

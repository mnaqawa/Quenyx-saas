<?php

namespace App\Console\Commands;

use App\Models\ObserveMeta;
use App\Models\ObserveService;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Services\NativeObserveCheckRunner;
use Illuminate\Console\Command;

class RunObserveChecks extends Command
{
    protected $signature = 'observe:run-checks {--workspace_id= : Run only for this workspace}';
    protected $description = 'Run native observe checks (no Nagios) and update ObserveService for Services UI';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace_id');
        $runner = new NativeObserveCheckRunner();

        $query = ObserveTargetHost::with(['services' => fn ($q) => $q->where('enabled', true)])
            ->where('enabled', true);
        if ($workspaceId !== null && $workspaceId !== '') {
            $query->where('workspace_id', (int) $workspaceId);
        }
        $hosts = $query->get();

        $run = 0;
        $errors = 0;

        foreach ($hosts as $host) {
            $workspaceId = $host->workspace_id;
            $prefix = 'ws' . $workspaceId . '-';
            $hostName = $prefix . $host->name;
            $address = trim((string) $host->address);
            if ($address === '') {
                continue;
            }

            // Hosts with no services: run Host-Alive (ping) check so they show real status instead of "Pending"
            if ($host->services->isEmpty()) {
                $engineKey = 'native';
                $engineServiceKey = "{$hostName}::Host-Alive";
                $existing = ObserveService::where('workspace_id', $workspaceId)
                    ->where('engine_key', $engineKey)
                    ->where('engine_service_key', $engineServiceKey)
                    ->first();

                $intervalSec = 5;
                $now = now();
                $due = $existing === null
                    || $existing->next_check_at === null
                    || $existing->next_check_at->getTimestamp() <= $now->getTimestamp();

                if ($due) {
                    try {
                        $result = $runner->run('ping', $address, [], [
                            'workspace_id' => $workspaceId,
                            'host_name' => $hostName,
                            'service_name' => 'Host-Alive',
                        ]);
                    } catch (\Throwable $e) {
                        $result = [
                            'state' => 'unknown',
                            'output' => 'Check failed: ' . $e->getMessage(),
                            'perfdata' => null,
                        ];
                        $errors++;
                    }

                    $nextCheck = $now->copy()->addSeconds($intervalSec);
                    $payload = [
                        'workspace_id' => $workspaceId,
                        'engine_key' => $engineKey,
                        'engine_service_key' => $engineServiceKey,
                        'host_name' => $hostName,
                        'service_name' => 'Host-Alive',
                        'state' => $result['state'],
                        'last_check_at' => $now,
                        'next_check_at' => $nextCheck,
                        'output' => $result['output'],
                        'plugin_output' => $result['output'],
                        'perfdata' => $result['perfdata'] ?? null,
                        'attempt' => '1/3',
                        'current_attempt' => 1,
                        'max_attempts' => 3,
                    ];

                    if ($existing !== null && $existing->state !== $result['state']) {
                        $payload['last_state_change_at'] = $now;
                        $payload['duration_sec'] = 0;
                    } elseif ($existing !== null && $existing->last_state_change_at) {
                        $payload['last_state_change_at'] = $existing->last_state_change_at;
                        $payload['duration_sec'] = (int) $existing->last_state_change_at->diffInSeconds($now);
                    } else {
                        $payload['duration_sec'] = 0;
                    }

                    ObserveService::updateOrCreate(
                        [
                            'workspace_id' => $workspaceId,
                            'engine_key' => $engineKey,
                            'engine_service_key' => $engineServiceKey,
                        ],
                        $payload
                    );
                    $run++;
                }
            }

            foreach ($host->services as $service) {
                $serviceName = $service->name;
                $engineKey = 'native';
                $engineServiceKey = "{$hostName}::{$serviceName}";

                $existing = ObserveService::where('workspace_id', $workspaceId)
                    ->where('engine_key', $engineKey)
                    ->where('engine_service_key', $engineServiceKey)
                    ->first();

                $intervalSec = (int) ($service->check_interval ?? 5);
                $intervalSec = $intervalSec >= 1 ? $intervalSec : 5;
                $now = now();
                $due = $existing === null
                    || $existing->next_check_at === null
                    || $existing->next_check_at->getTimestamp() <= $now->getTimestamp();

                if (! $due) {
                    continue;
                }

                $checkArgs = is_array($service->check_args) ? $service->check_args : [];
                $serviceKey = $service->service_key ?? '';
                $checkCommand = $service->check_command ?? '';
                // Resolve check_command from definition when missing (e.g. disk, load, cpu)
                if ($checkCommand === '' && $serviceKey !== '' && \Illuminate\Support\Facades\Schema::hasTable('observe_service_definitions')) {
                    $def = ObserveServiceDefinition::where('engine', 'nagios')->where('service_key', $serviceKey)->first();
                    if ($def && $def->check_command !== '') {
                        $checkCommand = $def->check_command;
                    }
                }
                // NRPE-style types (disk, load, swap, ssh, etc.): run as plugin with script name = check_command
                if (!in_array($serviceKey, ['http', 'tcp_port', 'ping', 'plugin'], true) && $checkCommand !== '') {
                    $checkArgs = array_merge($checkArgs, ['plugin' => $checkCommand]);
                    $serviceKey = 'plugin';
                }
                $context = [
                    'workspace_id' => $workspaceId,
                    'host_name' => $hostName,
                    'service_name' => $serviceName,
                ];

                try {
                    $result = $runner->run($serviceKey, $address, $checkArgs, $context);
                } catch (\Throwable $e) {
                    $result = [
                        'state' => 'unknown',
                        'output' => 'Check failed: ' . $e->getMessage(),
                        'perfdata' => null,
                    ];
                    $errors++;
                }

                $nextCheck = $now->copy()->addSeconds($intervalSec);
                $payload = [
                    'workspace_id' => $workspaceId,
                    'engine_key' => $engineKey,
                    'engine_service_key' => $engineServiceKey,
                    'host_name' => $hostName,
                    'service_name' => $serviceName,
                    'state' => $result['state'],
                    'last_check_at' => $now,
                    'next_check_at' => $nextCheck,
                    'output' => $result['output'],
                    'plugin_output' => $result['output'],
                    'perfdata' => $result['perfdata'] ?? null,
                    'attempt' => '1/3',
                    'current_attempt' => 1,
                    'max_attempts' => 3,
                ];

                if ($existing !== null && $existing->state !== $result['state']) {
                    $payload['last_state_change_at'] = $now;
                    $payload['duration_sec'] = 0;
                } elseif ($existing !== null && $existing->last_state_change_at) {
                    $payload['last_state_change_at'] = $existing->last_state_change_at;
                    $payload['duration_sec'] = (int) $existing->last_state_change_at->diffInSeconds($now);
                } else {
                    $payload['duration_sec'] = 0;
                }

                ObserveService::updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'engine_key' => $engineKey,
                        'engine_service_key' => $engineServiceKey,
                    ],
                    $payload
                );
                $run++;
            }
        }

        // Update meta for native engine (workspaces we have hosts in) so UI shows last run
        $workspaceIds = $hosts->pluck('workspace_id')->unique();
        foreach ($workspaceIds as $wid) {
            $nativeServices = ObserveService::where('workspace_id', $wid)->where('engine_key', 'native')->get();
            $totals = [
                'ok' => $nativeServices->where('state', 'ok')->count(),
                'warning' => $nativeServices->where('state', 'warning')->count(),
                'critical' => $nativeServices->where('state', 'critical')->count(),
                'unknown' => $nativeServices->where('state', 'unknown')->count(),
                'pending' => $nativeServices->where('state', 'pending')->count(),
                'unreachable' => $nativeServices->where('state', 'unreachable')->count(),
            ];
            ObserveMeta::updateOrCreate(
                ['workspace_id' => $wid, 'engine_key' => 'native'],
                ['last_poll_at' => now(), 'service_totals_json' => $totals, 'error' => null]
            );
        }

        $this->info("Ran {$run} native check(s).");
        if ($errors > 0) {
            $this->warn("{$errors} error(s).");
        }
        return $errors > 0 ? 1 : 0;
    }
}

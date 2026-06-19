<?php

namespace App\Console\Commands;

use App\Models\ObserveMeta;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Services\NativeObserveCheckRunner;
use App\Services\ObserveServiceKeyResolver;
use App\Services\PerfMetricExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RunObserveChecks extends Command
{
    protected $signature = 'observe:run-checks {--workspace_id= : Run only for this workspace}';
    protected $description = 'Run native observe checks (no Nagios) and update ObserveService for Services UI';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace_id');
        $runner = new NativeObserveCheckRunner();
        $extractor = new PerfMetricExtractor();

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
                    $this->recordHistory($extractor, $workspaceId, $hostName, 'Host-Alive', $result, $now);
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
                $checkCommand = $service->check_command ?? '';
                $serviceKey = app(ObserveServiceKeyResolver::class)->resolve(
                    (string) ($service->service_key ?? ''),
                    (string) $checkCommand,
                    (string) ($service->name ?? '')
                );
                $originalServiceKey = $serviceKey;
                // Resolve check_command from definition when missing (e.g. disk, load, cpu)
                if ($checkCommand === '' && $serviceKey !== '' && \Illuminate\Support\Facades\Schema::hasTable('observe_service_definitions')) {
                    // Engine-agnostic lookup to support existing definitions during migration to native-only runtime.
                    $def = ObserveServiceDefinition::where('service_key', $serviceKey)->first();
                    if ($def && $def->check_command !== '') {
                        $checkCommand = $def->check_command;
                    }
                }

                $definition = null;
                if ($originalServiceKey !== '' && \Illuminate\Support\Facades\Schema::hasTable('observe_service_definitions')) {
                    $definition = ObserveServiceDefinition::where('service_key', $originalServiceKey)->first();
                }
                $checkArgs = app(\App\Services\ObserveCheckArgsResolver::class)
                    ->resolve($originalServiceKey !== '' ? $originalServiceKey : $serviceKey, $address, $checkArgs, $definition);

                // NRPE-style types (disk, load, swap, ssh, etc.): run as plugin with script name = check_command
                if (! in_array($serviceKey, NativeObserveCheckRunner::NATIVE_SERVICE_KEYS, true) && $checkCommand !== '') {
                    $pluginName = strtolower(preg_replace('/!.*/', '', trim($checkCommand)));
                    $checkArgs = array_merge($checkArgs, ['plugin' => $pluginName !== '' ? $pluginName : $checkCommand]);
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
                $this->recordHistory($extractor, $workspaceId, $hostName, $serviceName, $result, $now);
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

        // Retention: prune old history occasionally (not on every tick) to bound table growth.
        $this->pruneHistory();

        $this->info("Ran {$run} native check(s).");
        if ($errors > 0) {
            $this->warn("{$errors} error(s).");
        }
        return $errors > 0 ? 1 : 0;
    }

    /**
     * Persist per-metric history samples derived from a check result. Best-effort:
     * any failure is logged and never interrupts the check loop.
     *
     * @param  array{state: string, output: string|null, perfdata: string|null}  $result
     */
    private function recordHistory(
        PerfMetricExtractor $extractor,
        int $workspaceId,
        string $hostName,
        string $serviceName,
        array $result,
        \Illuminate\Support\Carbon $now
    ): void {
        if (! Schema::hasTable('observe_metrics_history')) {
            return;
        }

        try {
            $metrics = $extractor->extract($serviceName, $result['perfdata'] ?? null, $result['output'] ?? null);
            if (empty($metrics)) {
                return;
            }
            $rows = [];
            foreach ($metrics as $metric => $value) {
                $rows[] = [
                    'workspace_id' => $workspaceId,
                    'host_name' => $hostName,
                    'service_name' => $serviceName,
                    'metric' => $metric,
                    'value' => $value,
                    'recorded_at' => $now,
                ];
            }
            if (! empty($rows)) {
                ObserveMetricHistory::insert($rows);
            }
        } catch (\Throwable $e) {
            Log::debug('RunObserveChecks::recordHistory failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Delete history older than the retention window. Gated by probability so it
     * runs roughly once per ~50 invocations instead of every minute.
     */
    private function pruneHistory(): void
    {
        if (! Schema::hasTable('observe_metrics_history')) {
            return;
        }

        if (mt_rand(1, 50) !== 1) {
            return;
        }
        try {
            $days = (int) config('observe.metrics_retention_days', 31);
            $days = $days >= 1 ? $days : 31;
            ObserveMetricHistory::where('recorded_at', '<', now()->subDays($days))->delete();
        } catch (\Throwable $e) {
            Log::debug('RunObserveChecks::pruneHistory failed', ['error' => $e->getMessage()]);
        }
    }
}

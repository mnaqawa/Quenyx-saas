<?php

namespace App\Console\Commands;

use App\Models\ObserveMeta;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use App\Models\ObserveServiceDefinition;
use App\Models\ObserveTargetHost;
use App\Services\NativeObserveCheckRunner;
use App\Services\ObserveCheckArgsResolver;
use App\Services\ObserveServiceKeyResolver;
use App\Services\PerfMetricExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RunObserveChecks extends Command
{
    protected $signature = 'observe:run-checks {--workspace_id= : Run only for this workspace}';

    protected $description = 'Run native observe checks (no Nagios) and update ObserveService for Services UI';

    /** @var array<string, ObserveServiceDefinition> */
    private array $definitionsByKey = [];

    public function handle(): int
    {
        $workspaceId = $this->option('workspace_id');
        $runner = new NativeObserveCheckRunner();
        $extractor = new PerfMetricExtractor();
        $this->definitionsByKey = $this->loadDefinitionsByKey();

        $query = ObserveTargetHost::with(['services' => fn ($q) => $q->where('enabled', true)])
            ->where('enabled', true);
        if ($workspaceId !== null && $workspaceId !== '') {
            $query->where('workspace_id', (int) $workspaceId);
        }
        $hosts = $query->get();

        $existingByWorkspace = $this->preloadExistingServices($hosts->pluck('workspace_id')->unique()->all());

        $run = 0;
        $errors = 0;
        $maxChecks = (int) config('observe.max_checks_per_run', 0);
        $now = now();

        foreach ($hosts as $host) {
            $workspaceId = $host->workspace_id;
            $prefix = 'ws' . $workspaceId . '-';
            $hostName = $prefix . $host->name;

            // Platform Agent hosts: use push telemetry, never SSH/pull plugins
            if ($host->agent_id && $host->source === 'agent') {
                $existingForWorkspace = $existingByWorkspace[$workspaceId] ?? [];
                $bridge = app(\App\Services\PlatformAgent\AgentTelemetryObserveBridge::class);
                $synced = $bridge->syncHost($host, $existingForWorkspace, $now);
                $existingByWorkspace[$workspaceId] = $existingForWorkspace;
                $run += $synced;

                continue;
            }

            $address = trim((string) $host->address);
            if ($address === '') {
                continue;
            }

            $existingForWorkspace = $existingByWorkspace[$workspaceId] ?? [];

            if ($host->services->isEmpty()) {
                if ($maxChecks > 0 && $run >= $maxChecks) {
                    break;
                }
                $result = $this->runHostAliveIfDue(
                    $runner,
                    $extractor,
                    $workspaceId,
                    $hostName,
                    $address,
                    $existingForWorkspace,
                    $now
                );
                if ($result['ran']) {
                    $run++;
                    if ($result['error']) {
                        $errors++;
                    }
                }
                continue;
            }

            foreach ($host->services as $service) {
                if ($maxChecks > 0 && $run >= $maxChecks) {
                    break 2;
                }

                $serviceName = $service->name;
                $engineServiceKey = "{$hostName}::{$serviceName}";
                $existing = $existingForWorkspace[$engineServiceKey] ?? null;

                $intervalSec = $this->resolveIntervalSeconds($service->check_interval);
                if (! $this->isCheckDue($existing, $intervalSec, $now)) {
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

                if ($checkCommand === '' && $serviceKey !== '' && $this->definitionsByKey !== []) {
                    $def = $this->definitionsByKey[$serviceKey] ?? null;
                    if ($def && $def->check_command !== '') {
                        $checkCommand = $def->check_command;
                    }
                }

                $definition = $originalServiceKey !== ''
                    ? ($this->definitionsByKey[$originalServiceKey] ?? null)
                    : null;
                $checkArgs = app(ObserveCheckArgsResolver::class)
                    ->resolve($originalServiceKey !== '' ? $originalServiceKey : $serviceKey, $address, $checkArgs, $definition);

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

                $saved = $this->persistServiceResult(
                    $workspaceId,
                    $hostName,
                    $serviceName,
                    $engineServiceKey,
                    $existing,
                    $result,
                    $intervalSec,
                    $now
                );
                $existingForWorkspace[$engineServiceKey] = $saved;
                $this->recordHistory($extractor, $workspaceId, $hostName, $serviceName, $result, $now);
                $run++;
            }
        }

        foreach ($hosts->pluck('workspace_id')->unique() as $wid) {
            $this->updateWorkspaceMeta((int) $wid);
        }

        $this->pruneHistory();

        $this->info("Ran {$run} native check(s).");
        if ($errors > 0) {
            $this->warn("{$errors} error(s).");
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * @return array<string, ObserveServiceDefinition>
     */
    private function loadDefinitionsByKey(): array
    {
        if (! Schema::hasTable('observe_service_definitions')) {
            return [];
        }

        return ObserveServiceDefinition::query()
            ->get()
            ->keyBy('service_key')
            ->all();
    }

    /**
     * @param  list<int>  $workspaceIds
     * @return array<int, array<string, ObserveService>>
     */
    private function preloadExistingServices(array $workspaceIds): array
    {
        if ($workspaceIds === []) {
            return [];
        }

        $rows = ObserveService::query()
            ->where('engine_key', 'native')
            ->whereIn('workspace_id', $workspaceIds)
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->workspace_id][$row->engine_service_key] = $row;
        }

        return $grouped;
    }

    private function resolveIntervalSeconds(?int $storedInterval): int
    {
        $default = (int) config('observe.default_check_interval_seconds', 300);
        $min = (int) config('observe.min_check_interval_seconds', 60);
        $interval = $storedInterval ?? $default;

        return max($min, $interval >= 1 ? $interval : $default);
    }

    private function isCheckDue(?ObserveService $existing, int $intervalSec, \Illuminate\Support\Carbon $now): bool
    {
        if ($existing === null || $existing->next_check_at === null) {
            return true;
        }

        return $existing->next_check_at->getTimestamp() <= $now->getTimestamp();
    }

    /**
     * @param  array<string, ObserveService>  $existingForWorkspace
     * @return array{ran: bool, error: bool}
     */
    private function runHostAliveIfDue(
        NativeObserveCheckRunner $runner,
        PerfMetricExtractor $extractor,
        int $workspaceId,
        string $hostName,
        string $address,
        array &$existingForWorkspace,
        \Illuminate\Support\Carbon $now
    ): array {
        $engineServiceKey = "{$hostName}::Host-Alive";
        $existing = $existingForWorkspace[$engineServiceKey] ?? null;
        $intervalSec = $this->resolveIntervalSeconds(null);

        if (! $this->isCheckDue($existing, $intervalSec, $now)) {
            return ['ran' => false, 'error' => false];
        }

        try {
            $result = $runner->run('ping', $address, [], [
                'workspace_id' => $workspaceId,
                'host_name' => $hostName,
                'service_name' => 'Host-Alive',
            ]);
            $error = false;
        } catch (\Throwable $e) {
            $result = [
                'state' => 'unknown',
                'output' => 'Check failed: ' . $e->getMessage(),
                'perfdata' => null,
            ];
            $error = true;
        }

        $saved = $this->persistServiceResult(
            $workspaceId,
            $hostName,
            'Host-Alive',
            $engineServiceKey,
            $existing,
            $result,
            $intervalSec,
            $now
        );
        $existingForWorkspace[$engineServiceKey] = $saved;
        $this->recordHistory($extractor, $workspaceId, $hostName, 'Host-Alive', $result, $now);

        return ['ran' => true, 'error' => $error];
    }

    /**
     * @param  array{state: string, output: string, perfdata: string|null}  $result
     */
    private function persistServiceResult(
        int $workspaceId,
        string $hostName,
        string $serviceName,
        string $engineServiceKey,
        ?ObserveService $existing,
        array $result,
        int $intervalSec,
        \Illuminate\Support\Carbon $now
    ): ObserveService {
        $nextCheck = $now->copy()->addSeconds($intervalSec);
        $payload = [
            'workspace_id' => $workspaceId,
            'engine_key' => 'native',
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

        return ObserveService::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'engine_key' => 'native',
                'engine_service_key' => $engineServiceKey,
            ],
            $payload
        );
    }

    private function updateWorkspaceMeta(int $workspaceId): void
    {
        $counts = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('engine_key', 'native')
            ->select('state', DB::raw('count(*) as aggregate'))
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $totals = [
            'ok' => (int) ($counts['ok'] ?? 0),
            'warning' => (int) ($counts['warning'] ?? 0),
            'critical' => (int) ($counts['critical'] ?? 0),
            'unknown' => (int) ($counts['unknown'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'unreachable' => (int) ($counts['unreachable'] ?? 0),
        ];

        ObserveMeta::updateOrCreate(
            ['workspace_id' => $workspaceId, 'engine_key' => 'native'],
            ['last_poll_at' => now(), 'service_totals_json' => $totals, 'error' => null]
        );
    }

    /**
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

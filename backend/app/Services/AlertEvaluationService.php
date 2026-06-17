<?php

namespace App\Services;

use App\Events\AlertOpened;
use App\Events\AlertResolved;
use App\Models\ObserveAlertEvalState;
use App\Models\ObserveAlertEvent;
use App\Models\ObserveAlertRule;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluates enabled alert rules against real QynSight monitoring data.
 * Workspace-scoped, idempotent, and duplicate-safe.
 */
class AlertEvaluationService
{
    /** @var array<int, array<string, mixed>|null> */
    private array $capacityCache = [];

    private bool $verbose = false;

    /** @var list<array<string, mixed>> */
    private array $debugEntries = [];

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDebugEntries(): array
    {
        return $this->debugEntries;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logEvaluatorSkip(ObserveAlertRule $rule, string $reason, array $context = []): void
    {
        $payload = array_merge([
            'rule_id' => $rule->id,
            'workspace_id' => $rule->workspace_id,
            'metric' => $rule->metric_condition,
            'reason' => $reason,
        ], $context);

        logger()->info('Alert evaluator skipped rule', $payload);
        $this->debugEntries[] = $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logEvaluatorDebug(ObserveAlertRule $rule, string $event, array $context = []): void
    {
        if (! $this->verbose) {
            return;
        }

        $payload = array_merge([
            'rule_id' => $rule->id,
            'workspace_id' => $rule->workspace_id,
            'metric' => $rule->metric_condition,
            'event' => $event,
        ], $context);

        $this->debugEntries[] = $payload;
    }

    /**
     * @return array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}
     */
    public function evaluate(?int $workspaceId = null): array
    {
        $this->debugEntries = [];

        if (! Schema::hasTable('observe_alert_rules') || ! Schema::hasTable('observe_alert_events')) {
            return ['evaluated' => 0, 'opened' => 0, 'resolved' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $stats = ['evaluated' => 0, 'opened' => 0, 'resolved' => 0, 'updated' => 0, 'skipped' => 0];

        $query = ObserveAlertRule::query()->where('enabled', true);
        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        foreach ($query->cursor() as $rule) {
            $stats['evaluated']++;
            try {
                $this->evaluateRule($rule, $stats);
            } catch (\Throwable $e) {
                Log::warning('AlertEvaluationService: rule failed', [
                    'rule_id' => $rule->id,
                    'workspace_id' => $rule->workspace_id,
                    'error' => $e->getMessage(),
                ]);
                $this->logEvaluatorSkip($rule, 'rule_evaluation_exception', [
                    'error' => $e->getMessage(),
                ]);
                $stats['skipped']++;
            }
        }

        return $stats;
    }

  /**
     * @param  array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}  $stats
     */
    private function evaluateRule(ObserveAlertRule $rule, array &$stats): void
    {
        $condition = config('alerts.conditions.' . $rule->metric_condition);
        if (! $condition) {
            $this->logEvaluatorSkip($rule, 'unknown_metric_condition', [
                'configured_keys' => array_keys(config('alerts.conditions', [])),
            ]);
            $stats['skipped']++;

            return;
        }

        $this->logEvaluatorDebug($rule, 'rule_start', [
            'target_scope' => $rule->target_scope,
            'operator' => $rule->operator,
            'threshold_value' => $rule->threshold_value,
            'duration_seconds' => $rule->duration_seconds,
            'condition_source' => $condition['source'] ?? null,
            'condition_scope' => $condition['scope'] ?? null,
        ]);

        $scope = $condition['scope'] ?? 'service';

        if ($scope === 'workspace') {
            $this->evaluateWorkspaceTarget($rule, $condition, $stats);

            return;
        }

        $targets = $this->resolveTargets($rule, $condition);
        if (empty($targets)) {
            $hostCount = ObserveTargetHost::query()
                ->where('workspace_id', $rule->workspace_id)
                ->where('enabled', true)
                ->count();
            $this->logEvaluatorSkip($rule, 'no_targets', [
                'target_scope' => $rule->target_scope,
                'target_host_id' => $rule->target_host_id,
                'target_service_key' => $rule->target_service_key,
                'condition_service_key' => $condition['service_key'] ?? null,
                'enabled_hosts' => $hostCount,
            ]);
            $stats['skipped']++;

            return;
        }

        $this->logEvaluatorDebug($rule, 'targets_resolved', [
            'target_count' => count($targets),
            'targets' => array_map(fn ($t) => [
                'host_name' => $t['host_name'],
                'service_name' => $t['service_name'],
                'prefixed_host' => $t['prefixed_host'],
            ], $targets),
        ]);

        foreach ($targets as $target) {
            $this->evaluateTarget($rule, $condition, $target, $stats);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}  $stats
     */
    private function evaluateWorkspaceTarget(ObserveAlertRule $rule, array $condition, array &$stats): void
    {
        $value = $this->resolveCapacityValue($rule->workspace_id, (string) ($condition['field'] ?? ''), $rule, $condition);
        if ($value === null) {
            $this->logEvaluatorSkip($rule, 'no_capacity_value', [
                'field' => $condition['field'] ?? null,
                'source_table' => 'CapacityPlanningService (observe_metrics_history)',
            ]);
            $this->handleConditionCleared($rule, [
                'target_host_id' => null,
                'target_service_key' => null,
                'host_name' => null,
                'service_name' => null,
            ], $stats);

            return;
        }

        $this->processEvaluation(
            $rule,
            [
                'target_host_id' => null,
                'target_service_key' => null,
                'host_name' => null,
                'service_name' => null,
            ],
            $value,
            $stats
        );
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null, prefixed_host: string}  $target
     * @param  array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}  $stats
     */
    private function evaluateTarget(ObserveAlertRule $rule, array $condition, array $target, array &$stats): void
    {
        $resolution = $this->resolveValue($rule->workspace_id, $condition, $target, $rule);
        $value = $resolution['value'];
        if ($value === null) {
            $this->logEvaluatorSkip($rule, $resolution['reason'] ?? 'no_metric_value', array_merge([
                'host_name' => $target['host_name'],
                'service_name' => $target['service_name'],
                'prefixed_host' => $target['prefixed_host'],
            ], $resolution['context'] ?? []));
            $this->handleConditionCleared($rule, $target, $stats);

            return;
        }

        $this->logEvaluatorDebug($rule, 'metric_resolved', [
            'host_name' => $target['host_name'],
            'service_name' => $target['service_name'],
            'metric_value' => $value,
            'source_table' => $resolution['source_table'] ?? null,
            'query_service_name' => $resolution['query_service_name'] ?? null,
            'query_metric' => $resolution['query_metric'] ?? null,
        ]);

        $this->processEvaluation($rule, $target, $value, $stats);
    }

    /**
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null}  $target
     * @param  array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}  $stats
     */
    private function processEvaluation(ObserveAlertRule $rule, array $target, float $value, array &$stats): void
    {
        $breached = $this->compare($value, $rule->operator, (float) $rule->threshold_value);
        $now = now();

        if (! $breached) {
            $this->logEvaluatorSkip($rule, 'threshold_not_breached', [
                'host_name' => $target['host_name'],
                'service_name' => $target['service_name'],
                'metric_value' => $value,
                'operator' => $rule->operator,
                'threshold_value' => $rule->threshold_value,
            ]);
            $this->handleConditionCleared($rule, $target, $stats);

            return;
        }

        $evalState = $this->getOrCreateEvalState($rule, $target, $now);
        $duration = max(0, (int) $rule->duration_seconds);

        if ($evalState->condition_met_since === null) {
            $evalState->update([
                'condition_met_since' => $now,
                'last_evaluated_at' => $now,
                'last_value' => $value,
            ]);
            if ($duration > 0) {
                $this->logEvaluatorSkip($rule, 'duration_pending_first_observation', [
                    'host_name' => $target['host_name'],
                    'service_name' => $target['service_name'],
                    'metric_value' => $value,
                    'duration_seconds' => $duration,
                ]);
                $stats['skipped']++;

                return;
            }
        } elseif ($duration > 0) {
            $elapsed = $evalState->condition_met_since->diffInSeconds($now);
            if ($elapsed < $duration) {
                $evalState->update(['last_evaluated_at' => $now, 'last_value' => $value]);
                $this->logEvaluatorSkip($rule, 'duration_pending', [
                    'host_name' => $target['host_name'],
                    'service_name' => $target['service_name'],
                    'metric_value' => $value,
                    'duration_seconds' => $duration,
                    'elapsed_seconds' => $elapsed,
                ]);
                $stats['skipped']++;

                return;
            }
        }

        $openEvent = $this->findOpenEvent($rule, $target);
        if ($openEvent) {
            $openEvent->update([
                'last_seen_at' => $now,
                'occurrence_count' => ($openEvent->occurrence_count ?? 1) + 1,
            ]);
            $evalState->update(['last_evaluated_at' => $now, 'last_value' => $value]);
            $stats['updated']++;

            return;
        }

        $event = $this->openAlert($rule, $target, $value, $now);
        $evalState->update(['last_evaluated_at' => $now, 'last_value' => $value]);
        AlertOpened::dispatch($event);
        $this->logEvaluatorDebug($rule, 'alert_opened', [
            'host_name' => $target['host_name'],
            'service_name' => $target['service_name'],
            'metric_value' => $value,
            'event_id' => $event->id,
        ]);
        $stats['opened']++;
    }

    /**
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null}  $target
     * @param  array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}  $stats
     */
    private function handleConditionCleared(ObserveAlertRule $rule, array $target, array &$stats): void
    {
        $this->clearEvalState($rule, $target);

        $openEvent = $this->findOpenEvent($rule, $target);
        if (! $openEvent) {
            return;
        }

        $now = now();
        $openEvent->update([
            'status' => 'resolved',
            'resolved_at' => $now,
        ]);
        AlertResolved::dispatch($openEvent->fresh());
        $stats['resolved']++;
    }

    /**
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null}  $target
     */
    private function openAlert(ObserveAlertRule $rule, array $target, float $value, Carbon $now): ObserveAlertEvent
    {
        $title = $this->buildTitle($rule, $target, $value);
        $message = $this->buildMessage($rule, $target, $value);

        $event = ObserveAlertEvent::create([
            'workspace_id' => $rule->workspace_id,
            'alert_rule_id' => $rule->id,
            'target_host_id' => $target['target_host_id'],
            'target_service_key' => $target['target_service_key'],
            'host_name' => $target['host_name'],
            'service_name' => $target['service_name'],
            'severity' => $rule->severity,
            'title' => $title,
            'message' => $message,
            'status' => 'open',
            'triggered_at' => $now,
            'opened_at' => $now,
            'last_seen_at' => $now,
            'occurrence_count' => 1,
            'metadata' => [
                'metric_condition' => $rule->metric_condition,
                'operator' => $rule->operator,
                'threshold_value' => $rule->threshold_value,
                'observed_value' => $value,
            ],
        ]);

        $rule->update([
            'last_triggered_at' => $now,
            'trigger_count_7d' => ObserveAlertEvent::query()
                ->where('alert_rule_id', $rule->id)
                ->where('triggered_at', '>=', now()->subDays(7))
                ->count(),
        ]);

        return $event;
    }

    /**
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null}  $target
     */
    private function findOpenEvent(ObserveAlertRule $rule, array $target): ?ObserveAlertEvent
    {
        $openStatuses = config('alerts.open_statuses', ['open', 'active', 'acknowledged']);

        return ObserveAlertEvent::query()
            ->where('workspace_id', $rule->workspace_id)
            ->where('alert_rule_id', $rule->id)
            ->whereIn('status', $openStatuses)
            ->where('target_host_id', $target['target_host_id'])
            ->where('target_service_key', $target['target_service_key'])
            ->where('host_name', $target['host_name'])
            ->where('service_name', $target['service_name'])
            ->first();
    }

    /**
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null}  $target
     */
    private function getOrCreateEvalState(ObserveAlertRule $rule, array $target, Carbon $now): ObserveAlertEvalState
    {
        if (! Schema::hasTable('observe_alert_eval_states')) {
            return new ObserveAlertEvalState([
                'condition_met_since' => null,
                'last_evaluated_at' => $now,
            ]);
        }

        return ObserveAlertEvalState::firstOrCreate(
            [
                'workspace_id' => $rule->workspace_id,
                'alert_rule_id' => $rule->id,
                'target_host_id' => $target['target_host_id'],
                'target_service_key' => $target['target_service_key'],
                'host_name' => $target['host_name'],
                'service_name' => $target['service_name'],
            ],
            ['last_evaluated_at' => $now]
        );
    }

    /**
     * @param  array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null}  $target
     */
    private function clearEvalState(ObserveAlertRule $rule, array $target): void
    {
        if (! Schema::hasTable('observe_alert_eval_states')) {
            return;
        }

        ObserveAlertEvalState::query()
            ->where('workspace_id', $rule->workspace_id)
            ->where('alert_rule_id', $rule->id)
            ->where('target_host_id', $target['target_host_id'])
            ->where('target_service_key', $target['target_service_key'])
            ->where('host_name', $target['host_name'])
            ->where('service_name', $target['service_name'])
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<int, array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null, prefixed_host: string}>
     */
    private function resolveTargets(ObserveAlertRule $rule, array $condition): array
    {
        $workspaceId = $rule->workspace_id;
        $prefix = 'ws' . $workspaceId . '-';
        $scope = $condition['scope'] ?? 'service';

        if ($rule->target_scope === 'selected_target' && $rule->target_host_id) {
            $host = ObserveTargetHost::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', $rule->target_host_id)
                ->first();
            if (! $host) {
                return [];
            }

            return $this->targetsForHost($host, $prefix, $scope, $rule->target_service_key);
        }

        if ($rule->target_scope === 'selected_service' && $rule->target_host_id && $rule->target_service_key) {
            $host = ObserveTargetHost::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', $rule->target_host_id)
                ->first();
            if (! $host) {
                return [];
            }

            return $this->targetsForHost($host, $prefix, 'service', $rule->target_service_key);
        }

        $hosts = ObserveTargetHost::query()
            ->where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->get();

        $conditionServiceKey = $condition['service_key'] ?? null;
        $targets = [];
        foreach ($hosts as $host) {
            foreach ($this->targetsForHost($host, $prefix, $scope, $conditionServiceKey ?? $rule->target_service_key) as $t) {
                $targets[] = $t;
            }
        }

        return $targets;
    }

    /**
     * @return array<int, array{target_host_id: int|null, target_service_key: string|null, host_name: string, service_name: string|null, prefixed_host: string}>
     */
    private function targetsForHost(ObserveTargetHost $host, string $prefix, string $scope, ?string $serviceKeyFilter): array
    {
        $prefixedHost = $prefix . $host->name;

        if ($scope === 'host') {
            return [[
                'target_host_id' => $host->id,
                'target_service_key' => null,
                'host_name' => $host->name,
                'service_name' => null,
                'prefixed_host' => $prefixedHost,
            ]];
        }

        $services = $host->services()->where('enabled', true)->get();
        $targets = [];
        foreach ($services as $service) {
            $key = $service->service_key ?? $service->name;
            if ($serviceKeyFilter && $key !== $serviceKeyFilter && $service->name !== $serviceKeyFilter) {
                continue;
            }
            $targets[] = [
                'target_host_id' => $host->id,
                'target_service_key' => $key,
                'host_name' => $host->name,
                'service_name' => $service->name,
                'prefixed_host' => $prefixedHost,
            ];
        }

        if (empty($targets) && $scope === 'service') {
            $targets[] = [
                'target_host_id' => $host->id,
                'target_service_key' => $serviceKeyFilter,
                'host_name' => $host->name,
                'service_name' => $serviceKeyFilter ?? 'ping',
                'prefixed_host' => $prefixedHost,
            ];
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array{prefixed_host: string, service_name: string|null, host_name: string}  $target
     * @return array{value: ?float, reason?: string, source_table?: string, query_service_name?: string, query_metric?: string, context?: array<string, mixed>}
     */
    private function resolveValue(int $workspaceId, array $condition, array $target, ObserveAlertRule $rule): array
    {
        $source = $condition['source'] ?? '';

        return match ($source) {
            'metric_history' => $this->resolveMetricHistoryValue(
                $workspaceId,
                $target['prefixed_host'],
                $this->metricHistoryServiceNameCandidates($target, $condition),
                (string) ($condition['metric'] ?? '')
            ),
            'service_state' => $this->resolveServiceStateValue(
                $workspaceId,
                $target['prefixed_host'],
                $target['service_name'] ?? '',
                (string) ($condition['match_state'] ?? 'critical')
            ),
            'service_state_numeric' => $this->resolveServiceStateNumericValue(
                $workspaceId,
                $target['prefixed_host'],
                $target['service_name'] ?? ''
            ),
            'host_state' => $this->resolveHostStateValue(
                $workspaceId,
                $target['prefixed_host'],
                $condition['match_states'] ?? ['unreachable']
            ),
            default => [
                'value' => null,
                'reason' => 'unknown_metric_source',
                'source_table' => null,
                'context' => ['source' => $source],
            ],
        };
    }

    /**
     * observe:run-checks stores metrics under the target service display name (e.g. "CPU usage"),
     * not the config service_key (e.g. "cpu"). Try display name first, then service_key fallbacks.
     *
     * @param  array{service_name?: string|null, target_service_key?: string|null}  $target
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    private function metricHistoryServiceNameCandidates(array $target, array $condition): array
    {
        $candidates = [];

        foreach ([
            $target['service_name'] ?? null,
            $target['target_service_key'] ?? null,
            $condition['service_key'] ?? null,
        ] as $name) {
            $name = trim((string) $name);
            if ($name !== '' && ! in_array($name, $candidates, true)) {
                $candidates[] = $name;
            }
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $serviceNameCandidates
     * @return array{value: ?float, reason?: string, source_table?: string, query_service_name?: string, query_metric?: string, context?: array<string, mixed>}
     */
    private function resolveMetricHistoryValue(int $workspaceId, string $hostName, array $serviceNameCandidates, string $metric): array
    {
        if (! Schema::hasTable('observe_metrics_history')) {
            return [
                'value' => null,
                'reason' => 'metrics_history_table_missing',
                'source_table' => 'observe_metrics_history',
                'query_service_name' => $serviceNameCandidates[0] ?? '',
                'query_metric' => $metric,
            ];
        }

        $tried = [];
        foreach ($serviceNameCandidates as $serviceName) {
            $tried[] = $serviceName;
            $value = ObserveMetricHistory::query()
                ->where('workspace_id', $workspaceId)
                ->where('host_name', $hostName)
                ->where('service_name', $serviceName)
                ->where('metric', $metric)
                ->orderByDesc('recorded_at')
                ->value('value');

            if ($value !== null) {
                return [
                    'value' => (float) $value,
                    'source_table' => 'observe_metrics_history',
                    'query_service_name' => $serviceName,
                    'query_metric' => $metric,
                    'context' => ['service_name_candidates_tried' => $tried],
                ];
            }
        }

        $alternateServiceNames = ObserveMetricHistory::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->where('metric', $metric)
            ->distinct()
            ->pluck('service_name')
            ->all();

        return [
            'value' => null,
            'reason' => 'no_metric_history_row',
            'source_table' => 'observe_metrics_history',
            'query_service_name' => $tried[0] ?? '',
            'query_metric' => $metric,
            'context' => [
                'host_name_queried' => $hostName,
                'service_name_candidates_tried' => $tried,
                'available_service_names_for_metric' => $alternateServiceNames,
            ],
        ];
    }

    /**
     * @return array{value: ?float, reason?: string, source_table?: string, context?: array<string, mixed>}
     */
    private function resolveServiceStateValue(int $workspaceId, string $hostName, string $serviceName, string $matchState): array
    {
        $state = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->where('service_name', $serviceName)
            ->value('state');

        if ($state === null) {
            $available = ObserveService::query()
                ->where('workspace_id', $workspaceId)
                ->where('host_name', $hostName)
                ->pluck('service_name')
                ->all();

            return [
                'value' => null,
                'reason' => 'no_service_state_row',
                'source_table' => 'observe_services',
                'context' => [
                    'query_service_name' => $serviceName,
                    'match_state' => $matchState,
                    'available_service_names' => $available,
                ],
            ];
        }

        return [
            'value' => strtolower((string) $state) === strtolower($matchState) ? 1.0 : 0.0,
            'source_table' => 'observe_services',
            'context' => ['observed_state' => $state, 'match_state' => $matchState],
        ];
    }

    /**
     * @return array{value: ?float, reason?: string, source_table?: string, context?: array<string, mixed>}
     */
    private function resolveServiceStateNumericValue(int $workspaceId, string $hostName, string $serviceName): array
    {
        $state = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->where('service_name', $serviceName)
            ->value('state');

        if ($state === null) {
            return [
                'value' => null,
                'reason' => 'no_service_state_row',
                'source_table' => 'observe_services',
                'context' => ['query_service_name' => $serviceName],
            ];
        }

        $map = config('alerts.state_severity_map', []);

        return [
            'value' => (float) ($map[strtolower((string) $state)] ?? 0),
            'source_table' => 'observe_services',
            'context' => ['observed_state' => $state],
        ];
    }

    /**
     * @param  array<int, string>  $matchStates
     * @return array{value: ?float, reason?: string, source_table?: string, context?: array<string, mixed>}
     */
    private function resolveHostStateValue(int $workspaceId, string $hostName, array $matchStates): array
    {
        $states = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->pluck('state')
            ->map(fn ($s) => strtolower((string) $s));

        if ($states->isEmpty()) {
            return [
                'value' => null,
                'reason' => 'no_host_service_rows',
                'source_table' => 'observe_services',
                'context' => ['host_name' => $hostName, 'match_states' => $matchStates],
            ];
        }

        $match = collect($matchStates)->map(fn ($s) => strtolower($s));
        $worst = $states->contains(fn ($s) => $match->contains($s));

        return [
            'value' => $worst ? 1.0 : 0.0,
            'source_table' => 'observe_services',
            'context' => ['observed_states' => $states->unique()->values()->all(), 'match_states' => $matchStates],
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function resolveCapacityValue(int $workspaceId, string $field, ObserveAlertRule $rule, array $condition): ?float
    {
        if ($field === '') {
            $this->logEvaluatorSkip($rule, 'capacity_field_missing', [
                'source_table' => 'CapacityPlanningService',
            ]);

            return null;
        }

        $payload = $this->capacityPayload($workspaceId);
        if ($payload === null) {
            $this->logEvaluatorDebug($rule, 'capacity_payload_unavailable', [
                'field' => $field,
                'source_table' => 'CapacityPlanningService (observe_metrics_history)',
            ]);

            return null;
        }

        $summary = $payload['summary'] ?? [];
        if (array_key_exists($field, $summary) && $summary[$field] !== null) {
            $this->logEvaluatorDebug($rule, 'capacity_value_resolved', [
                'field' => $field,
                'metric_value' => (float) $summary[$field],
                'source_table' => 'CapacityPlanningService.summary',
            ]);

            return (float) $summary[$field];
        }

        $runway = $payload['runway'] ?? [];
        $map = [
            'cpu_runway_days' => $runway['cpu']['days'] ?? null,
            'memory_runway_days' => $runway['memory']['days'] ?? null,
            'storage_runway_days' => $runway['storage']['days'] ?? null,
        ];
        if (isset($map[$field]) && $map[$field] !== null) {
            $this->logEvaluatorDebug($rule, 'capacity_value_resolved', [
                'field' => $field,
                'metric_value' => (float) $map[$field],
                'source_table' => 'CapacityPlanningService.runway',
            ]);

            return (float) $map[$field];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function capacityPayload(int $workspaceId): ?array
    {
        if (array_key_exists($workspaceId, $this->capacityCache)) {
            return $this->capacityCache[$workspaceId];
        }

        try {
            $payload = app(CapacityPlanningService::class)->build($workspaceId, '30d');
            $this->capacityCache[$workspaceId] = $payload;
        } catch (\Throwable $e) {
            Log::debug('AlertEvaluationService: capacity unavailable', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
            $this->capacityCache[$workspaceId] = null;
        }

        return $this->capacityCache[$workspaceId];
    }

    private function compare(float $value, string $operator, float $threshold): bool
    {
        $op = $operator === '=' ? '==' : $operator;

        return match ($op) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '==' => abs($value - $threshold) < 0.0001,
            '!=' => abs($value - $threshold) >= 0.0001,
            default => false,
        };
    }

    /**
     * @param  array{host_name: string|null, service_name: string|null}  $target
     */
    private function buildTitle(ObserveAlertRule $rule, array $target, float $value): string
    {
        $parts = [$rule->name];
        if ($target['host_name']) {
            $parts[] = $target['host_name'];
        }
        if ($target['service_name']) {
            $parts[] = $target['service_name'];
        }

        return implode(' — ', $parts);
    }

    /**
     * @param  array{host_name: string|null, service_name: string|null}  $target
     */
    private function buildMessage(ObserveAlertRule $rule, array $target, float $value): string
    {
        $targetLabel = trim(($target['host_name'] ?? '') . ' / ' . ($target['service_name'] ?? ''), ' /');

        return sprintf(
            '%s %s %s (observed %.2f, threshold %s %s)',
            $rule->metric_condition,
            $targetLabel !== '' ? "on {$targetLabel}" : 'for workspace',
            'breached',
            $value,
            $rule->operator,
            $rule->threshold_value
        );
    }
}

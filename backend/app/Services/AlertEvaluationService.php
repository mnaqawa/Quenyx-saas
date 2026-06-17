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

    /**
     * @return array{evaluated: int, opened: int, resolved: int, updated: int, skipped: int}
     */
    public function evaluate(?int $workspaceId = null): array
    {
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
            $stats['skipped']++;

            return;
        }

        $scope = $condition['scope'] ?? 'service';

        if ($scope === 'workspace') {
            $this->evaluateWorkspaceTarget($rule, $condition, $stats);

            return;
        }

        $targets = $this->resolveTargets($rule, $condition);
        if (empty($targets)) {
            $stats['skipped']++;

            return;
        }

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
        $value = $this->resolveCapacityValue($rule->workspace_id, (string) ($condition['field'] ?? ''));
        if ($value === null) {
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
        $value = $this->resolveValue($rule->workspace_id, $condition, $target);
        if ($value === null) {
            $this->handleConditionCleared($rule, $target, $stats);

            return;
        }

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
                $stats['skipped']++;

                return;
            }
        } elseif ($duration > 0) {
            $elapsed = $evalState->condition_met_since->diffInSeconds($now);
            if ($elapsed < $duration) {
                $evalState->update(['last_evaluated_at' => $now, 'last_value' => $value]);
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
     */
    private function resolveValue(int $workspaceId, array $condition, array $target): ?float
    {
        $source = $condition['source'] ?? '';

        return match ($source) {
            'metric_history' => $this->latestMetricValue(
                $workspaceId,
                $target['prefixed_host'],
                (string) ($condition['service_key'] ?? $target['service_name'] ?? ''),
                (string) ($condition['metric'] ?? '')
            ),
            'service_state' => $this->serviceStateValue(
                $workspaceId,
                $target['prefixed_host'],
                $target['service_name'] ?? '',
                (string) ($condition['match_state'] ?? 'critical')
            ),
            'service_state_numeric' => $this->serviceStateNumeric(
                $workspaceId,
                $target['prefixed_host'],
                $target['service_name'] ?? ''
            ),
            'host_state' => $this->hostStateValue(
                $workspaceId,
                $target['prefixed_host'],
                $condition['match_states'] ?? ['unreachable']
            ),
            default => null,
        };
    }

    private function latestMetricValue(int $workspaceId, string $hostName, string $serviceName, string $metric): ?float
    {
        if (! Schema::hasTable('observe_metrics_history')) {
            return null;
        }

        $value = ObserveMetricHistory::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->where('service_name', $serviceName)
            ->where('metric', $metric)
            ->orderByDesc('recorded_at')
            ->value('value');

        return $value !== null ? (float) $value : null;
    }

    private function serviceStateValue(int $workspaceId, string $hostName, string $serviceName, string $matchState): ?float
    {
        $state = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->where('service_name', $serviceName)
            ->value('state');

        if ($state === null) {
            return null;
        }

        return strtolower((string) $state) === strtolower($matchState) ? 1.0 : 0.0;
    }

    private function serviceStateNumeric(int $workspaceId, string $hostName, string $serviceName): ?float
    {
        $state = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->where('service_name', $serviceName)
            ->value('state');

        if ($state === null) {
            return null;
        }

        $map = config('alerts.state_severity_map', []);

        return (float) ($map[strtolower((string) $state)] ?? 0);
    }

    /**
     * @param  array<int, string>  $matchStates
     */
    private function hostStateValue(int $workspaceId, string $hostName, array $matchStates): ?float
    {
        $states = ObserveService::query()
            ->where('workspace_id', $workspaceId)
            ->where('host_name', $hostName)
            ->pluck('state')
            ->map(fn ($s) => strtolower((string) $s));

        if ($states->isEmpty()) {
            return null;
        }

        $match = collect($matchStates)->map(fn ($s) => strtolower($s));
        $worst = $states->contains(fn ($s) => $match->contains($s));

        return $worst ? 1.0 : 0.0;
    }

    private function resolveCapacityValue(int $workspaceId, string $field): ?float
    {
        if ($field === '') {
            return null;
        }

        $payload = $this->capacityPayload($workspaceId);
        if ($payload === null) {
            return null;
        }

        $summary = $payload['summary'] ?? [];
        if (array_key_exists($field, $summary) && $summary[$field] !== null) {
            return (float) $summary[$field];
        }

        $runway = $payload['runway'] ?? [];
        $map = [
            'cpu_runway_days' => $runway['cpu']['days'] ?? null,
            'memory_runway_days' => $runway['memory']['days'] ?? null,
            'storage_runway_days' => $runway['storage']['days'] ?? null,
        ];
        if (isset($map[$field]) && $map[$field] !== null) {
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

<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveAlertEvent;
use App\Models\ObserveMetricHistory;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Services\CapacityPlanningService;
use App\Support\Observe\OperationsEntityId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sprint 21 — Operations Intelligence evidence collector.
 *
 * The SINGLE source of operational EVIDENCE. Every value returned here is read directly from the
 * QynSight ("Observe") tables for the given workspace — nothing is fabricated, forecasted-by-guess,
 * or invented. The AI layer only ever narrates the evidence this collector produces; if the data
 * is missing, the evidence honestly says so. Reuses {@see CapacityPlanningService} for capacity
 * analytics (no duplicated forecasting math).
 */
class OperationsEvidenceCollector
{
    /** State severity ordering: higher = worse (used to rank "most unhealthy"). */
    private const STATE_RANK = [
        'critical' => 5,
        'unreachable' => 4,
        'warning' => 3,
        'unknown' => 2,
        'pending' => 1,
        'ok' => 0,
    ];

    public function __construct(
        private readonly CapacityPlanningService $capacity,
    ) {}

    public function hostPrefix(Project $project): string
    {
        return 'ws'.$project->id.'-';
    }

    /**
     * Strip the "ws{id}-" prefix monitoring rows use for host names.
     */
    public function unprefixHost(Project $project, string $hostName): string
    {
        $prefix = $this->hostPrefix($project);

        return str_starts_with($hostName, $prefix) ? substr($hostName, strlen($prefix)) : $hostName;
    }

    /**
     * Workspace-wide infrastructure health rollup: host inventory + service-state counts +
     * the worst-off hosts (derived from their service states). Real data only.
     *
     * @return array<string, mixed>
     */
    public function infrastructureHealth(Project $project): array
    {
        $prefix = $this->hostPrefix($project);

        $hosts = ObserveTargetHost::query()
            ->where('workspace_id', $project->id)
            ->get(['id', 'name', 'address', 'enabled', 'source']);

        $services = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $prefix.'%')
            ->get(['host_name', 'service_name', 'state', 'last_state_change_at', 'output']);

        $stateCounts = $services->groupBy('state')->map->count();

        // Worst state per host (string-keyed by un-prefixed host name).
        $worstByHost = [];
        foreach ($services as $service) {
            $host = $this->unprefixHost($project, (string) $service->host_name);
            $current = $worstByHost[$host] ?? 'ok';
            if ($this->rank((string) $service->state) > $this->rank($current)) {
                $worstByHost[$host] = (string) $service->state;
            }
        }

        $unhealthy = [];
        foreach ($hosts as $host) {
            $worst = $worstByHost[$host->name] ?? 'pending';
            if (in_array($worst, ['critical', 'unreachable', 'warning'], true)) {
                $unhealthy[] = [
                    'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_HOST, $project->id, (int) $host->id),
                    'name' => (string) $host->name,
                    'address' => (string) $host->address,
                    'worst_state' => $worst,
                ];
            }
        }

        usort($unhealthy, fn ($a, $b): int => $this->rank($b['worst_state']) <=> $this->rank($a['worst_state']));

        return [
            'hosts_total' => $hosts->count(),
            'hosts_enabled' => $hosts->where('enabled', true)->count(),
            'services_total' => $services->count(),
            'service_state_counts' => [
                'ok' => (int) ($stateCounts['ok'] ?? 0),
                'warning' => (int) ($stateCounts['warning'] ?? 0),
                'critical' => (int) ($stateCounts['critical'] ?? 0),
                'unknown' => (int) ($stateCounts['unknown'] ?? 0),
                'pending' => (int) ($stateCounts['pending'] ?? 0),
                'unreachable' => (int) ($stateCounts['unreachable'] ?? 0),
            ],
            'unhealthy_hosts' => array_slice($unhealthy, 0, 20),
            'unhealthy_host_count' => count($unhealthy),
        ];
    }

    /**
     * Open (and recently-triggered) alert events for the workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function openAlerts(Project $project, int $hours = 24, int $limit = 50): array
    {
        $events = ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where(function ($query) use ($hours): void {
                $query->whereIn('status', (array) config('alerts.open_statuses', ['open', 'active', 'acknowledged']))
                    ->orWhere('triggered_at', '>=', now()->subHours($hours));
            })
            ->orderByDesc('triggered_at')
            ->limit($limit)
            ->get();

        return $events->map(fn (ObserveAlertEvent $event): array => $this->alertSummary($project, $event))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function alertSummary(Project $project, ObserveAlertEvent $event): array
    {
        return [
            'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_ALERT, $project->id, (int) $event->id),
            'title' => (string) $event->title,
            'severity' => (string) $event->severity,
            'status' => (string) $event->status,
            'host' => $event->host_name !== null ? $this->unprefixHost($project, (string) $event->host_name) : null,
            'service' => $event->service_name,
            'message' => $event->message,
            'occurrence_count' => (int) $event->occurrence_count,
            'triggered_at' => optional($event->triggered_at)->toIso8601String(),
            'acknowledged_at' => optional($event->acknowledged_at)->toIso8601String(),
            'resolved_at' => optional($event->resolved_at)->toIso8601String(),
            'last_seen_at' => optional($event->last_seen_at)->toIso8601String(),
        ];
    }

    /**
     * Full evidence envelope for a single alert: the alert itself, its host, services on that host,
     * recent metric trends, and related alerts (same host, overlapping window).
     *
     * @return array<string, mixed>
     */
    public function alertEvidence(Project $project, ObserveAlertEvent $event): array
    {
        $hostName = $event->host_name !== null ? $this->unprefixHost($project, (string) $event->host_name) : null;
        $host = $hostName !== null
            ? ObserveTargetHost::query()->where('workspace_id', $project->id)->where('name', $hostName)->first()
            : null;

        $relatedAlerts = ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where('id', '!=', $event->id)
            ->when($event->host_name !== null, fn ($q) => $q->where('host_name', $event->host_name))
            ->where('triggered_at', '>=', optional($event->triggered_at)->subHours(6) ?? now()->subHours(6))
            ->orderByDesc('triggered_at')
            ->limit(15)
            ->get()
            ->map(fn (ObserveAlertEvent $e): array => $this->alertSummary($project, $e))
            ->all();

        return [
            'alert' => $this->alertSummary($project, $event),
            'metadata' => $event->metadata,
            'host' => $host !== null ? $this->hostSnapshot($project, $host) : null,
            'related_alerts' => $relatedAlerts,
            'recent_metrics' => $event->host_name !== null
                ? $this->recentMetrics($project, (string) $event->host_name, ['cpu', 'memory', 'disk', 'network'], 12)
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hostEvidence(Project $project, ObserveTargetHost $host): array
    {
        $prefix = $this->hostPrefix($project);
        $hostName = $prefix.$host->name;

        $services = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', $hostName)
            ->get(['id', 'service_name', 'state', 'output', 'last_check_at', 'last_state_change_at', 'check_latency_sec', 'execution_time_sec']);

        $openAlerts = ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where('host_name', $hostName)
            ->whereIn('status', (array) config('alerts.open_statuses', ['open', 'active', 'acknowledged']))
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn (ObserveAlertEvent $e): array => $this->alertSummary($project, $e))
            ->all();

        return [
            'host' => $this->hostSnapshot($project, $host),
            'services' => $services->map(fn (ObserveService $s): array => [
                'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_SERVICE, $project->id, (int) $s->id),
                'name' => (string) $s->service_name,
                'state' => (string) $s->state,
                'output' => $s->output,
                'last_check_at' => optional($s->last_check_at)->toIso8601String(),
                'last_state_change_at' => optional($s->last_state_change_at)->toIso8601String(),
                'check_latency_sec' => $s->check_latency_sec,
            ])->all(),
            'open_alerts' => $openAlerts,
            'recent_metrics' => $this->recentMetrics($project, $hostName, ['cpu', 'memory', 'disk', 'network'], 24),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceEvidence(Project $project, ObserveService $service): array
    {
        $hostName = (string) $service->host_name;

        return [
            'service' => [
                'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_SERVICE, $project->id, (int) $service->id),
                'name' => (string) $service->service_name,
                'host' => $this->unprefixHost($project, $hostName),
                'state' => (string) $service->state,
                'output' => $service->output,
                'perfdata' => $service->perfdata,
                'check_command' => $service->check_command,
                'last_check_at' => optional($service->last_check_at)->toIso8601String(),
                'last_state_change_at' => optional($service->last_state_change_at)->toIso8601String(),
                'check_latency_sec' => $service->check_latency_sec,
                'execution_time_sec' => $service->execution_time_sec,
            ],
            'recent_metrics' => $this->recentMetrics($project, $hostName, ['cpu', 'memory', 'disk', 'network'], 24, (string) $service->service_name),
            'open_alerts' => ObserveAlertEvent::query()
                ->where('workspace_id', $project->id)
                ->where('host_name', $hostName)
                ->whereIn('status', (array) config('alerts.open_statuses', ['open', 'active', 'acknowledged']))
                ->orderByDesc('triggered_at')
                ->limit(10)
                ->get()
                ->map(fn (ObserveAlertEvent $e): array => $this->alertSummary($project, $e))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hostSnapshot(Project $project, ObserveTargetHost $host): array
    {
        $prefix = $this->hostPrefix($project);
        $hostName = $prefix.$host->name;

        $latest = $this->latestMetrics($project, $hostName);

        return [
            'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_HOST, $project->id, (int) $host->id),
            'name' => (string) $host->name,
            'address' => (string) $host->address,
            'public_ip' => $host->public_ip,
            'source' => (string) $host->source,
            'enabled' => (bool) $host->enabled,
            'latest_metrics' => $latest,
        ];
    }

    /**
     * Latest cpu/memory/disk/network percentage for a (prefixed) host.
     *
     * @return array<string, float|null>
     */
    public function latestMetrics(Project $project, string $prefixedHostName): array
    {
        $out = [];
        foreach (['cpu', 'memory', 'disk', 'network'] as $metric) {
            $out[$metric] = ObserveMetricHistory::query()
                ->where('workspace_id', $project->id)
                ->where('host_name', $prefixedHostName)
                ->where('metric', $metric)
                ->orderByDesc('recorded_at')
                ->value('value');
        }

        return $out;
    }

    /**
     * Recent metric trend per metric: first/last/min/max/avg + a deterministic slope (per hour),
     * computed only from real samples. Returns "insufficient_data" when fewer than 2 samples exist.
     *
     * @param  list<string>  $metrics
     * @return array<string, mixed>
     */
    public function recentMetrics(Project $project, string $prefixedHostName, array $metrics, int $hours, ?string $serviceName = null): array
    {
        $from = now()->subHours($hours);
        $result = [];

        foreach ($metrics as $metric) {
            $rows = ObserveMetricHistory::query()
                ->where('workspace_id', $project->id)
                ->where('host_name', $prefixedHostName)
                ->where('metric', $metric)
                ->where('recorded_at', '>=', $from)
                ->when($serviceName !== null, fn ($q) => $q->where('service_name', $serviceName))
                ->orderBy('recorded_at')
                ->get(['value', 'recorded_at']);

            if ($rows->count() < 2) {
                $result[$metric] = ['available' => false, 'samples' => $rows->count()];

                continue;
            }

            $values = $rows->pluck('value')->map(fn ($v) => (float) $v);
            $result[$metric] = [
                'available' => true,
                'samples' => $rows->count(),
                'first' => round((float) $values->first(), 2),
                'last' => round((float) $values->last(), 2),
                'min' => round((float) $values->min(), 2),
                'max' => round((float) $values->max(), 2),
                'avg' => round((float) $values->avg(), 2),
                'slope_per_hour' => $this->slopePerHour($rows),
                'window_hours' => $hours,
            ];
        }

        return $result;
    }

    /**
     * Capacity analytics for the workspace (reuses CapacityPlanningService — no duplicated math).
     *
     * @return array<string, mixed>
     */
    public function capacity(Project $project, string $range = '30d'): array
    {
        return $this->capacity->build($project->id, $range);
    }

    /**
     * Operational changes in a recent window: service state transitions + newly-triggered and
     * resolved alerts. All from real timestamps.
     *
     * @return array<string, mixed>
     */
    public function changes(Project $project, int $hours = 24): array
    {
        $prefix = $this->hostPrefix($project);
        $from = now()->subHours($hours);

        $stateChanges = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', 'like', $prefix.'%')
            ->where('last_state_change_at', '>=', $from)
            ->orderByDesc('last_state_change_at')
            ->limit(50)
            ->get(['service_name', 'host_name', 'state', 'last_state_change_at', 'output'])
            ->map(fn (ObserveService $s): array => [
                'host' => $this->unprefixHost($project, (string) $s->host_name),
                'service' => (string) $s->service_name,
                'state' => (string) $s->state,
                'changed_at' => optional($s->last_state_change_at)->toIso8601String(),
            ])
            ->all();

        $newAlerts = ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where('triggered_at', '>=', $from)
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get()
            ->map(fn (ObserveAlertEvent $e): array => $this->alertSummary($project, $e))
            ->all();

        $resolvedAlerts = ObserveAlertEvent::query()
            ->where('workspace_id', $project->id)
            ->where('resolved_at', '>=', $from)
            ->orderByDesc('resolved_at')
            ->limit(50)
            ->get()
            ->map(fn (ObserveAlertEvent $e): array => $this->alertSummary($project, $e))
            ->all();

        return [
            'window_hours' => $hours,
            'service_state_changes' => $stateChanges,
            'new_alerts' => $newAlerts,
            'resolved_alerts' => $resolvedAlerts,
        ];
    }

    private function rank(string $state): int
    {
        return self::STATE_RANK[$state] ?? 0;
    }

    /**
     * Least-squares slope expressed per hour over the sample window.
     *
     * @param  Collection<int, ObserveMetricHistory>  $rows
     */
    private function slopePerHour(Collection $rows): float
    {
        $n = $rows->count();
        if ($n < 2) {
            return 0.0;
        }

        $t0 = Carbon::parse($rows->first()->recorded_at)->getTimestamp();
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;

        foreach ($rows as $row) {
            $x = (Carbon::parse($row->recorded_at)->getTimestamp() - $t0) / 3600.0; // hours
            $y = (float) $row->value;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denominator = ($n * $sumXX) - ($sumX * $sumX);
        if (abs($denominator) < 1e-9) {
            return 0.0;
        }

        return round((($n * $sumXY) - ($sumX * $sumY)) / $denominator, 4);
    }
}

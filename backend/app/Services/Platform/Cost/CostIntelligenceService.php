<?php

declare(strict_types=1);

namespace App\Services\Platform\Cost;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 25 — Enterprise Cost Intelligence (QynBalance).
 *
 * Cost analysis from REAL platform data: monitored hosts/services, enrolled agents, workspace seats, and
 * automation activity. It NEVER fabricates financial values — monetary estimates are produced only when
 * the operator has configured real unit rates (`config/cost.php`). When a rate is missing, the resource
 * COUNT is reported with an explicit `pricing_available: false` / "pricing unavailable" note.
 *
 * Optimization, utilization, and forecasting are evidence-based (idle agents, hosts without services,
 * automation runtime) and produce recommendations — never automatic changes.
 */
class CostIntelligenceService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        $currency = (string) config('cost.currency', 'USD');

        $infrastructure = $this->infrastructureCost($project, $currency);

        return [
            'currency' => $currency,
            'pricing_configured' => $this->anyRateConfigured(),
            'generated_at' => now()->toIso8601String(),
            'infrastructure' => $infrastructure,
            'license_optimization' => $this->licenseOptimization($project, $currency),
            'asset_utilization' => $this->assetUtilization($project),
            'automation_savings' => $this->automationSavings($project, $currency),
            'capacity_optimization' => $this->capacityOptimization($project),
            'cloud_optimization' => $this->cloudOptimization($project),
            'budget_forecast' => $this->budgetForecast($infrastructure, $currency),
            'recommendations' => $this->recommendations($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function infrastructureCost(Project $project, string $currency): array
    {
        $hosts = $this->count('observe_targets_hosts', 'workspace_id', $project->id);
        $services = $this->count('observe_services', 'workspace_id', $project->id);
        $agents = $this->count('agents', 'workspace_id', $project->id);

        $lines = [
            $this->line('hosts', $hosts, (float) config('cost.rates.host_per_month'), $currency),
            $this->line('services', $services, (float) config('cost.rates.service_per_month'), $currency),
            $this->line('agents', $agents, (float) config('cost.rates.agent_per_month'), $currency),
        ];

        $priced = array_filter($lines, static fn (array $l): bool => $l['pricing_available']);
        $total = array_sum(array_map(static fn (array $l): float => (float) ($l['monthly_cost'] ?? 0), $priced));

        return [
            'lines' => $lines,
            'estimated_monthly_total' => $priced === [] ? null : round($total, 2),
            'pricing_available' => $priced !== [],
            'note' => $priced === [] ? 'Pricing unavailable — configure unit rates in config/cost.php to see monetary estimates.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function licenseOptimization(Project $project, string $currency): array
    {
        $seats = Schema::hasTable('project_memberships')
            ? (int) DB::table('project_memberships')->where('project_id', $project->id)->count()
            : 0;

        $rate = config('cost.rates.license_per_seat');

        return [
            'seats' => $seats,
            'pricing_available' => $rate !== null,
            'monthly_cost' => $rate !== null ? round((float) $rate * $seats, 2) : null,
            'currency' => $currency,
            'note' => $rate === null ? 'Seat pricing unavailable — set cost.rates.license_per_seat to estimate license cost.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assetUtilization(Project $project): array
    {
        $hosts = $this->count('observe_targets_hosts', 'workspace_id', $project->id);
        $services = $this->count('observe_services', 'workspace_id', $project->id);
        $agents = $this->count('agents', 'workspace_id', $project->id);

        // Idle agents: not seen within the configured window (real last_seen_at).
        $idleAgents = 0;
        if (Schema::hasTable('agents')) {
            $hours = (int) config('cost.optimization.idle_agent_hours', 72);
            $idleAgents = (int) DB::table('agents')->where('workspace_id', $project->id)
                ->where(function ($q) use ($hours): void {
                    $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours($hours));
                })->count();
        }

        return [
            'available' => ($hosts + $agents + $services) > 0,
            'hosts' => $hosts,
            'services' => $services,
            'agents' => $agents,
            'idle_agents' => $idleAgents,
            'services_per_host' => $hosts > 0 ? round($services / $hosts, 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function automationSavings(Project $project, string $currency): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return ['available' => false, 'reason' => 'Automation platform not present.'];
        }

        $succeeded = (int) DB::table('automation_executions')->where('project_id', $project->id)->where('status', 'succeeded')->count();
        $runtimeMs = (int) DB::table('automation_executions')->where('project_id', $project->id)->whereNotNull('duration_ms')->sum('duration_ms');
        $runtimeMinutes = round($runtimeMs / 60000, 1);

        $rate = config('cost.rates.automation_run_minute');

        return [
            'available' => true,
            'successful_executions' => $succeeded,
            'automated_runtime_minutes' => $runtimeMinutes,
            'pricing_available' => $rate !== null,
            'estimated_savings' => $rate !== null ? round((float) $rate * $runtimeMinutes, 2) : null,
            'currency' => $currency,
            'note' => $rate === null
                ? 'Savings value unavailable — set cost.rates.automation_run_minute to estimate labor savings from automation.'
                : 'Estimate based on automated runtime vs. manual effort at the configured per-minute rate.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function capacityOptimization(Project $project): array
    {
        // Evidence-based pointer to QynSight capacity (no fabricated rightsizing numbers here).
        $samples = Schema::hasTable('observe_metrics_history')
            ? (int) DB::table('observe_metrics_history')->where('workspace_id', $project->id)->count() : 0;

        return [
            'available' => $samples > 0,
            'metric_samples' => $samples,
            'note' => $samples > 0
                ? 'Use QynSight Capacity Planning for rightsizing; idle/over-provisioned resources surface as recommendations.'
                : 'No metric samples yet — capacity-based rightsizing requires collected metrics.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cloudOptimization(Project $project): array
    {
        // Quenyx does not ingest cloud billing by default — be explicit rather than invent cloud spend.
        return [
            'available' => false,
            'reason' => 'No cloud billing source connected. Connect a cloud cost source via Integrations to enable cloud resource optimization.',
        ];
    }

    /**
     * @param  array<string, mixed>  $infrastructure
     * @return array<string, mixed>
     */
    public function budgetForecast(array $infrastructure, string $currency): array
    {
        $monthly = $infrastructure['estimated_monthly_total'] ?? null;
        $budget = config('cost.monthly_budget');

        if ($monthly === null) {
            return ['available' => false, 'reason' => 'Forecast requires configured unit rates (pricing unavailable).'];
        }

        return [
            'available' => true,
            'currency' => $currency,
            'estimated_monthly' => $monthly,
            'projected_annual' => round((float) $monthly * 12, 2),
            'monthly_budget' => $budget !== null ? (float) $budget : null,
            'over_budget' => $budget !== null ? ((float) $monthly > (float) $budget) : null,
            'note' => $budget === null ? 'Set cost.monthly_budget to compare against budget.' : null,
        ];
    }

    /**
     * Evidence-based optimization recommendations (counts/ratios → suggested action). Never auto-applied.
     *
     * @return list<array<string, mixed>>
     */
    public function recommendations(Project $project): array
    {
        $recs = [];
        $util = $this->assetUtilization($project);

        if (($util['idle_agents'] ?? 0) > 0) {
            $recs[] = [
                'key' => 'decommission_idle_agents',
                'severity' => 'medium',
                'evidence' => $util['idle_agents'].' agent(s) not seen within the idle window.',
                'recommendation' => 'Review idle agents for decommissioning to reduce monitoring footprint and cost.',
            ];
        }

        if (config('cost.optimization.flag_hosts_without_services', true) && Schema::hasTable('observe_targets_hosts') && Schema::hasTable('observe_services')) {
            $hostsWithServices = DB::table('observe_services')->where('workspace_id', $project->id)->distinct()->count('host_name');
            $totalHosts = (int) ($util['hosts'] ?? 0);
            $without = max(0, $totalHosts - (int) $hostsWithServices);
            if ($without > 0) {
                $recs[] = [
                    'key' => 'hosts_without_services',
                    'severity' => 'low',
                    'evidence' => $without.' host(s) have no monitored services attached.',
                    'recommendation' => 'Attach service checks or remove unused hosts to right-size monitoring.',
                ];
            }
        }

        if (! $this->anyRateConfigured()) {
            $recs[] = [
                'key' => 'configure_pricing',
                'severity' => 'info',
                'evidence' => 'No unit rates configured.',
                'recommendation' => 'Configure real unit rates in config/cost.php to unlock monetary cost and savings estimates.',
            ];
        }

        return $recs;
    }

    private function anyRateConfigured(): bool
    {
        foreach ((array) config('cost.rates', []) as $rate) {
            if ($rate !== null && $rate !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $resource, int $count, ?float $rate, string $currency): array
    {
        $hasRate = $rate !== null && $rate > 0;

        return [
            'resource' => $resource,
            'count' => $count,
            'unit_rate' => $hasRate ? $rate : null,
            'pricing_available' => $hasRate,
            'monthly_cost' => $hasRate ? round($rate * $count, 2) : null,
            'currency' => $currency,
            'note' => $hasRate ? null : 'pricing unavailable',
        ];
    }

    private function count(string $table, string $column, int $value): int
    {
        return Schema::hasTable($table) ? (int) DB::table($table)->where($column, $value)->count() : 0;
    }
}

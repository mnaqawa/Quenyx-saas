<?php

declare(strict_types=1);

namespace App\Services\Platform\Analytics;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 25 — the shared Enterprise Analytics platform.
 *
 * Deterministic, evidence-based metrics computed from REAL rows only: MTTD/MTTR, incident trends,
 * automation effectiveness, AI adoption, knowledge usage, asset growth, capacity trends, notification
 * statistics, and executive KPIs. Every metric is honest about data availability — when there is not
 * enough data it returns `available: false` with a reason instead of inventing a number.
 *
 * Pure READ-MODEL: it writes nothing and reuses the tables each module already owns
 * (`project_id` for app modules; `workspace_id` for the Observe/agent schema — both reference projects).
 */
class EnterpriseAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Project $project, int $days = 30): array
    {
        $days = max(1, min($days, 365));

        return [
            'window_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'mttd' => $this->mttd($project),
            'mttr' => $this->mttr($project),
            'incident_trends' => $this->incidentTrends($project, $days),
            'automation_effectiveness' => $this->automationEffectiveness($project),
            'ai_adoption' => $this->aiAdoption($project),
            'knowledge_usage' => $this->knowledgeUsage($project),
            'asset_growth' => $this->assetGrowth($project),
            'capacity_trends' => $this->capacityTrends($project),
            'notification_statistics' => $this->notificationStatistics($project),
            'executive_kpis' => $this->executiveKpis($project),
        ];
    }

    /**
     * Mean Time To Detect — proxy: alert triggered_at → acknowledged_at on the Observe alert stream.
     *
     * @return array<string, mixed>
     */
    public function mttd(Project $project): array
    {
        if (! Schema::hasTable('observe_alert_events')) {
            return $this->unavailable('Observe alert stream not present.');
        }

        $rows = DB::table('observe_alert_events')
            ->where('workspace_id', $project->id)
            ->whereNotNull('acknowledged_at')
            ->whereNotNull('triggered_at')
            ->get(['triggered_at', 'acknowledged_at']);

        return $this->avgSeconds($rows, 'triggered_at', 'acknowledged_at', 'No acknowledged alerts yet.');
    }

    /**
     * Mean Time To Resolve — incidents opened_at → resolved_at (primary) with an alert MTTR companion.
     *
     * @return array<string, mixed>
     */
    public function mttr(Project $project): array
    {
        $incident = $this->unavailable('No resolved incidents yet.');
        if (Schema::hasTable('incidents')) {
            $rows = DB::table('incidents')
                ->where('project_id', $project->id)
                ->whereNotNull('resolved_at')
                ->whereNotNull('opened_at')
                ->get(['opened_at', 'resolved_at']);
            $incident = $this->avgSeconds($rows, 'opened_at', 'resolved_at', 'No resolved incidents yet.');
        }

        $alert = $this->unavailable('No resolved alerts yet.');
        if (Schema::hasTable('observe_alert_events')) {
            $rows = DB::table('observe_alert_events')
                ->where('workspace_id', $project->id)
                ->whereNotNull('resolved_at')
                ->whereNotNull('triggered_at')
                ->get(['triggered_at', 'resolved_at']);
            $alert = $this->avgSeconds($rows, 'triggered_at', 'resolved_at', 'No resolved alerts yet.');
        }

        return ['incident' => $incident, 'alert' => $alert];
    }

    /**
     * @return array<string, mixed>
     */
    public function incidentTrends(Project $project, int $days): array
    {
        if (! Schema::hasTable('incidents')) {
            return $this->unavailable('Incident table not present.');
        }

        $since = now()->subDays($days);
        $bySeverity = DB::table('incidents')
            ->where('project_id', $project->id)
            ->where('created_at', '>=', $since)
            ->select('severity', DB::raw('count(*) as c'))
            ->groupBy('severity')->pluck('c', 'severity')->all();

        $opened = DB::table('incidents')->where('project_id', $project->id)->where('created_at', '>=', $since)->count();
        $resolved = DB::table('incidents')->where('project_id', $project->id)->where('resolved_at', '>=', $since)->count();

        return [
            'available' => true,
            'opened' => $opened,
            'resolved' => $resolved,
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function automationEffectiveness(Project $project): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return $this->unavailable('Automation platform not present.');
        }

        $byStatus = DB::table('automation_executions')
            ->where('project_id', $project->id)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->all();

        $total = array_sum($byStatus);
        $succeeded = (int) ($byStatus['succeeded'] ?? 0);
        $failed = (int) ($byStatus['failed'] ?? 0);
        $decided = $succeeded + $failed;

        return [
            'available' => $total > 0,
            'total_executions' => $total,
            'by_status' => $byStatus,
            'success_rate' => $decided > 0 ? round($succeeded / $decided * 100, 1) : null,
            'rolled_back' => (int) DB::table('automation_executions')->where('project_id', $project->id)->where('rolled_back', true)->count(),
            'note' => $decided === 0 ? 'No completed (succeeded/failed) executions yet — success rate unavailable.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aiAdoption(Project $project): array
    {
        if (! Schema::hasTable('ai_conversations')) {
            return $this->unavailable('AI platform not present.');
        }

        $conversations = DB::table('ai_conversations')->where('project_id', $project->id)->count();
        $tokens = (int) DB::table('ai_conversations')->where('project_id', $project->id)->sum('total_tokens');
        $byProvider = DB::table('ai_conversations')
            ->where('project_id', $project->id)
            ->select('provider', DB::raw('count(*) as c'))
            ->groupBy('provider')->pluck('c', 'provider')->all();

        return [
            'available' => true,
            'conversations' => $conversations,
            'total_tokens' => $tokens,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function knowledgeUsage(Project $project): array
    {
        if (! Schema::hasTable('knowledge_documents')) {
            return $this->unavailable('Knowledge platform not present.');
        }

        return [
            'available' => true,
            'documents' => DB::table('knowledge_documents')->where('project_id', $project->id)->count(),
            'by_status' => DB::table('knowledge_documents')->where('project_id', $project->id)
                ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assetGrowth(Project $project): array
    {
        $hosts = Schema::hasTable('observe_targets_hosts')
            ? (int) DB::table('observe_targets_hosts')->where('workspace_id', $project->id)->count() : 0;
        $agents = Schema::hasTable('agents')
            ? (int) DB::table('agents')->where('workspace_id', $project->id)->count() : 0;
        $services = Schema::hasTable('observe_services')
            ? (int) DB::table('observe_services')->where('workspace_id', $project->id)->count() : 0;

        return [
            'available' => ($hosts + $agents + $services) > 0,
            'hosts' => $hosts,
            'agents' => $agents,
            'services' => $services,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function capacityTrends(Project $project): array
    {
        if (! Schema::hasTable('observe_metrics_history')) {
            return $this->unavailable('Metrics history not present.');
        }

        $samples = (int) DB::table('observe_metrics_history')->where('workspace_id', $project->id)->count();

        return [
            'available' => $samples > 0,
            'samples' => $samples,
            'note' => $samples === 0 ? 'No metric samples recorded yet.' : 'See QynSight Capacity Planning for full trend analysis.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notificationStatistics(Project $project): array
    {
        if (! Schema::hasTable('notifications')) {
            return $this->unavailable('Notification platform not present.');
        }

        return [
            'available' => true,
            'total' => DB::table('notifications')->where('project_id', $project->id)->count(),
            'by_severity' => DB::table('notifications')->where('project_id', $project->id)
                ->select('severity', DB::raw('count(*) as c'))->groupBy('severity')->pluck('c', 'severity')->all(),
            'by_status' => DB::table('notifications')->where('project_id', $project->id)
                ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->all(),
            'by_channel' => DB::table('notifications')->where('project_id', $project->id)
                ->select('channel', DB::raw('count(*) as c'))->groupBy('channel')->pluck('c', 'channel')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executiveKpis(Project $project): array
    {
        return [
            'open_incidents' => Schema::hasTable('incidents')
                ? (int) DB::table('incidents')->where('project_id', $project->id)->whereNotIn('status', ['resolved', 'closed'])->count() : 0,
            'open_tickets' => Schema::hasTable('tickets')
                ? (int) DB::table('tickets')->where('project_id', $project->id)->whereNotIn('status', ['resolved', 'closed'])->count() : 0,
            'active_notifications' => Schema::hasTable('notifications')
                ? (int) DB::table('notifications')->where('project_id', $project->id)->whereIn('status', ['new', 'escalated'])->count() : 0,
            'open_alerts' => Schema::hasTable('observe_alert_events')
                ? (int) DB::table('observe_alert_events')->where('workspace_id', $project->id)->where('status', 'open')->count() : 0,
            'knowledge_documents' => Schema::hasTable('knowledge_documents')
                ? (int) DB::table('knowledge_documents')->where('project_id', $project->id)->count() : 0,
        ];
    }

    /**
     * Average seconds between two timestamp columns across a row set, formatted honestly.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function avgSeconds($rows, string $from, string $to, string $emptyReason): array
    {
        if ($rows->isEmpty()) {
            return $this->unavailable($emptyReason);
        }

        $deltas = [];
        foreach ($rows as $row) {
            $a = $row->{$from} ?? null;
            $b = $row->{$to} ?? null;
            if ($a === null || $b === null) {
                continue;
            }
            $deltas[] = max(0, strtotime((string) $b) - strtotime((string) $a));
        }

        if ($deltas === []) {
            return $this->unavailable($emptyReason);
        }

        $avg = (int) round(array_sum($deltas) / count($deltas));

        return [
            'available' => true,
            'sample_size' => count($deltas),
            'avg_seconds' => $avg,
            'human' => $this->humanizeSeconds($avg),
        ];
    }

    private function humanizeSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return round($seconds / 60, 1).'m';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1).'h';
        }

        return round($seconds / 86400, 1).'d';
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason];
    }
}

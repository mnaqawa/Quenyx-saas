<?php

declare(strict_types=1);

namespace App\Services\Platform\Executive;

use App\Models\Project;
use App\Models\User;
use App\Services\AI\ModuleAiNarrator;
use App\Services\Platform\Analytics\EnterpriseAnalyticsService;
use App\Services\Platform\Cost\CostIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 25 — Executive Intelligence.
 *
 * Evidence-based executive dashboards built ONLY from real data: operational/infrastructure/compliance
 * health, capacity forecast, top risks & recommendations, automation success, AI usage, and incident /
 * knowledge / cost KPIs. The executive AI summary is narrated through the shared {@see ModuleAiNarrator}
 * over this deterministic evidence — never fabricated. Health scores are deterministic functions of real
 * counts (documented inline), so the same data always yields the same score.
 */
class ExecutiveIntelligenceService
{
    private const ROLE_PREAMBLE = 'You are Quenyx AI producing an executive operations briefing. Use ONLY the '
        .'provided evidence (health scores, KPIs, risks, recommendations). Be concise and board-ready: posture, '
        .'what changed, top risks, and recommended focus. Never invent metrics, costs, or incidents. State clearly '
        .'when data is insufficient.';

    public function __construct(
        private readonly EnterpriseAnalyticsService $analytics,
        private readonly CostIntelligenceService $cost,
        private readonly ModuleAiNarrator $narrator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Project $project): array
    {
        $analytics = $this->analytics->build($project);

        // PERF (GA remediation): compute the cost overview ONCE and derive both the
        // top recommendations and the cost KPIs from it. Previously dashboard() called
        // CostIntelligenceService::recommendations() AND ::overview() separately, and
        // overview() itself recomputes recommendations — duplicating the work.
        $costOverview = $this->cost->overview($project);

        return [
            'generated_at' => now()->toIso8601String(),
            'operational_health' => $this->operationalHealth($project),
            'infrastructure_health' => $this->infrastructureHealth($project),
            'compliance_health' => $this->complianceHealth($project),
            'capacity_forecast' => $analytics['capacity_trends'],
            'top_risks' => $this->topRisks($project),
            'top_recommendations' => $costOverview['recommendations'],
            'automation_success' => $analytics['automation_effectiveness'],
            'ai_usage' => $analytics['ai_adoption'],
            'incident_kpis' => $this->incidentKpis($project, $analytics),
            'knowledge_kpis' => $analytics['knowledge_usage'],
            'cost_kpis' => $this->costKpis($costOverview),
        ];
    }

    /**
     * Executive AI summary over the deterministic dashboard evidence.
     *
     * @return array<string, mixed>
     */
    public function summary(Project $project, ?User $user): array
    {
        $dashboard = $this->dashboard($project);
        $ai = $this->narrator->narrate(
            $project,
            $user,
            'executive_summary',
            $dashboard,
            'Write a board-ready executive summary of the current operational posture using only this evidence.',
            self::ROLE_PREAMBLE,
            'executive_intelligence_summary',
            'qynva.executive.summary',
            ModuleAiNarrator::DEFAULT_GUARDRAILS,
            'text',
            [['source_document_key' => 'platform.executive_evidence', 'official_reference' => 'Executive dashboard evidence', 'type' => 'executive']],
        );

        return ['dashboard' => $dashboard, 'executive_summary' => $ai];
    }

    /**
     * Operational health: 100 minus weighted penalties for open critical/high incidents and open alerts.
     *
     * @return array<string, mixed>
     */
    private function operationalHealth(Project $project): array
    {
        $openIncidents = Schema::hasTable('incidents')
            ? (int) DB::table('incidents')->where('project_id', $project->id)->whereNotIn('status', ['resolved', 'closed'])->count() : 0;
        $criticalIncidents = Schema::hasTable('incidents')
            ? (int) DB::table('incidents')->where('project_id', $project->id)->whereNotIn('status', ['resolved', 'closed'])->whereIn('severity', ['critical', 'high'])->count() : 0;
        $openAlerts = Schema::hasTable('observe_alert_events')
            ? (int) DB::table('observe_alert_events')->where('workspace_id', $project->id)->where('status', 'open')->count() : 0;

        $score = max(0, 100 - ($criticalIncidents * 15) - (($openIncidents - $criticalIncidents) * 5) - ($openAlerts * 3));

        return [
            'score' => $score,
            'status' => $this->band($score),
            'open_incidents' => $openIncidents,
            'critical_incidents' => $criticalIncidents,
            'open_alerts' => $openAlerts,
        ];
    }

    /**
     * Infrastructure health: share of monitored services currently in an OK state.
     *
     * @return array<string, mixed>
     */
    private function infrastructureHealth(Project $project): array
    {
        if (! Schema::hasTable('observe_services')) {
            return ['available' => false, 'reason' => 'No monitored services.'];
        }

        $byState = DB::table('observe_services')->where('workspace_id', $project->id)
            ->select('state', DB::raw('count(*) as c'))->groupBy('state')->pluck('c', 'state')->all();
        $total = array_sum($byState);

        if ($total === 0) {
            return ['available' => false, 'reason' => 'No monitored services yet.'];
        }

        $ok = (int) ($byState['ok'] ?? 0);
        $score = (int) round($ok / $total * 100);

        return [
            'available' => true,
            'score' => $score,
            'status' => $this->band($score),
            'services_total' => $total,
            'by_state' => $byState,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function complianceHealth(Project $project): array
    {
        if (! Schema::hasTable('compliance_gap_assessments')) {
            return ['available' => false, 'reason' => 'Compliance engine not present for this workspace.'];
        }

        $assessments = (int) DB::table('compliance_gap_assessments')->where('project_id', $project->id)->count();

        return [
            'available' => $assessments > 0,
            'assessments' => $assessments,
            'note' => $assessments === 0 ? 'No compliance assessments yet — see QynShield.' : 'See QynShield for detailed compliance posture.',
        ];
    }

    /**
     * Top risks from real open critical/high incidents and open critical alerts.
     *
     * @return list<array<string, mixed>>
     */
    private function topRisks(Project $project): array
    {
        $risks = [];

        if (Schema::hasTable('incidents')) {
            foreach (DB::table('incidents')->where('project_id', $project->id)
                ->whereNotIn('status', ['resolved', 'closed'])->whereIn('severity', ['critical', 'high'])
                ->orderByDesc('opened_at')->limit(5)->get(['uuid', 'title', 'severity', 'status']) as $i) {
                $risks[] = ['type' => 'incident', 'severity' => $i->severity, 'title' => $i->title, 'ref' => $i->uuid, 'status' => $i->status];
            }
        }

        if (Schema::hasTable('observe_alert_events')) {
            foreach (DB::table('observe_alert_events')->where('workspace_id', $project->id)
                ->where('status', 'open')->where('severity', 'critical')
                ->orderByDesc('triggered_at')->limit(5)->get(['title', 'severity']) as $a) {
                $risks[] = ['type' => 'alert', 'severity' => $a->severity, 'title' => $a->title];
            }
        }

        return $risks;
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @return array<string, mixed>
     */
    private function incidentKpis(Project $project, array $analytics): array
    {
        return [
            'mttr' => $analytics['mttr'],
            'mttd' => $analytics['mttd'],
            'trends' => $analytics['incident_trends'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview  Pre-computed cost overview (see dashboard()).
     * @return array<string, mixed>
     */
    private function costKpis(array $overview): array
    {
        return [
            'pricing_configured' => $overview['pricing_configured'],
            'estimated_monthly' => $overview['infrastructure']['estimated_monthly_total'] ?? null,
            'currency' => $overview['currency'],
            'recommendations' => count($overview['recommendations']),
        ];
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 85 => 'healthy',
            $score >= 60 => 'degraded',
            default => 'at_risk',
        };
    }
}

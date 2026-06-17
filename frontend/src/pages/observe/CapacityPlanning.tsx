import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { observeService } from '../../services/observeService'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import { CapacitySummaryCard } from '../../components/observe/capacity/CapacitySummaryCard'
import { CapacityHealthPanel } from '../../components/observe/capacity/CapacityHealthPanel'
import { CapacityChartContainer } from '../../components/observe/capacity/CapacityChartContainer'
import { ResourceConsumersTable } from '../../components/observe/capacity/ResourceConsumersTable'
import { TopCapacityRisksTable } from '../../components/observe/capacity/TopCapacityRisksTable'
import { InsightCard } from '../../components/observe/capacity/InsightCard'
import { ScenarioTemplateCard } from '../../components/observe/capacity/ScenarioTemplateCard'
import { BudgetForecastPanel } from '../../components/observe/capacity/BudgetForecastPanel'
import { AdvisorSection } from '../../components/observe/capacity/AdvisorSection'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import type { AIAgentSeed } from '../../types/aiAgent'
import type {
  CapacityPlanningRange,
  CapacityPlanningResponse,
  CapacityScenario,
  CapacityScenarioParams,
  CapacityStatus,
  CapacityTab,
} from '../../types/observe'

const FORECAST_COLORS = {
  cpu: '#0ea5e9',
  memory: '#10b981',
  storage: '#f59e0b',
}

function formatRunway(months: number | null, monthsLabel: string, insufficient: string): string {
  if (months === null) return insufficient
  return `${months} ${monthsLabel}`
}

function formatRisk(score: number | null, insufficient: string): string {
  if (score === null) return insufficient
  return `${Math.round(score)}/100`
}

export default function CapacityPlanning() {
  const { t } = useLanguage()
  const { selectedWorkspaceId } = useWorkspaceContext()

  const [range, setRange] = useState<CapacityPlanningRange>('30d')
  const [activeTab, setActiveTab] = useState<CapacityTab>('overview')
  const [data, setData] = useState<CapacityPlanningResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [configureOpen, setConfigureOpen] = useState(false)
  const [aiOpen, setAiOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)
  const [scenarioOverrides, setScenarioOverrides] = useState<Record<string, CapacityScenario>>({})

  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null

  const load = useCallback(
    (scenario?: CapacityScenarioParams) => {
      if (!wsId) {
        setData(null)
        setError(null)
        return
      }
      setLoading(true)
      setError(null)
      observeService
        .getCapacityPlanning(wsId, range, scenario)
        .then((res) => {
          setData(res)
          if (scenario?.scenario_template && res.scenarios?.calculated?.[0]) {
            setScenarioOverrides((prev) => ({
              ...prev,
              [scenario.scenario_template!]: res.scenarios!.calculated[0],
            }))
          }
        })
        .catch((err: unknown) => {
          setData(null)
          setError(err instanceof Error ? err.message : t('common.errorGeneric'))
        })
        .finally(() => setLoading(false))
    },
    [range, t, wsId],
  )

  useEffect(() => {
    setScenarioOverrides({})
    load()
  }, [load])

  const statusLabel = useCallback(
    (status: CapacityStatus): string => {
      switch (status) {
        case 'critical':
          return t('cap.status.critical')
        case 'warning':
          return t('cap.status.warning')
        case 'healthy':
          return t('cap.status.healthy')
        default:
          return t('cap.insufficientData')
      }
    },
    [t],
  )

  const scenarioName = useCallback(
    (name: string): string => {
      const key = `cap.scenario.${name}` as const
      const translated = t(key)
      return translated === key ? name : translated
    },
    [t],
  )

  const priorityLabel = useCallback(
    (priority: 'high' | 'medium' | 'low') => {
      if (priority === 'high') return t('cap.priority.high')
      if (priority === 'medium') return t('cap.priority.medium')
      return t('cap.priority.low')
    },
    [t],
  )

  const insightTypeLabel = useCallback(
    (type?: string) => {
      if (!type) return undefined
      const key = `cap.insight.type.${type}` as const
      const translated = t(key)
      return translated === key ? type : translated
    },
    [t],
  )

  const openAi = useCallback(() => {
    if (!data?.advisor?.available) return
    setAiSeed({
      id: Date.now(),
      agent: 'capacity_planner',
      question: t('cap.advisorAiQuestion'),
      autoSend: true,
      quick: true,
      context: {
        source: 'qynsight_capacity',
        metrics: {
          health: data.health,
          risk_score: data.summary.capacity_risk_score,
          runway: data.runway,
          advisor: data.advisor,
        },
        services: data.resource_analysis.distribution,
      },
    })
    setAiOpen(true)
  }, [data, t])

  const handleScenarioCalculate = useCallback(
    (params: CapacityScenarioParams) => {
      load(params)
    },
    [load],
  )

  const tabs = useMemo(
    () => [
      { id: 'overview', label: t('cap.tab.overview') },
      { id: 'resource-analysis', label: t('cap.tab.resourceAnalysis') },
      { id: 'optimization', label: t('cap.tab.optimization') },
      { id: 'scenarios', label: t('cap.tab.scenarios') },
      { id: 'budget', label: t('cap.tab.budget') },
    ],
    [t],
  )

  const rangeOptions: Array<{ value: CapacityPlanningRange; label: string }> = [
    { value: '7d', label: t('cap.range.7d') },
    { value: '30d', label: t('cap.range.30d') },
    { value: '90d', label: t('cap.range.90d') },
  ]

  const summary = data?.summary
  const health = data?.health

  const historicalForecast = (data?.overview.forecast ?? []).filter((p) => !p.projected)
  const projectedForecast = data?.overview.forecast ?? []
  const hasForecast = projectedForecast.some(
    (p) => p.cpu != null || p.memory != null || p.storage != null,
  )

  const topRisks = data?.top_risks ?? data?.resource_analysis.top_risks ?? []
  const scenarioTemplates = data?.scenarios?.templates ?? []
  const calculatedScenarios = data?.scenarios?.calculated ?? data?.scenario_planning ?? []

  const getScenarioResult = (templateId: string): CapacityScenario | undefined =>
    scenarioOverrides[templateId] ??
    calculatedScenarios.find((s) => s.id === templateId || s.template === templateId)

  const healthLabels = useMemo(
    () => ({
      title: t('cap.health.title'),
      healthStatus: t('cap.health.status'),
      riskScore: t('cap.kpi.riskScore'),
      primaryRisk: t('cap.health.primaryRisk'),
      shortestRunway: t('cap.health.shortestRunway'),
      recommendedAction: t('cap.health.recommendedAction'),
      dataConfidence: t('cap.health.dataConfidence'),
      insufficient: t('cap.insufficientData'),
      days: t('cap.days'),
      status: {
        healthy: t('cap.health.healthy'),
        watch: t('cap.health.watch'),
        risk: t('cap.health.risk'),
        critical: t('cap.health.critical'),
        no_data: t('cap.health.noData'),
      },
      confidence: {
        no_data: t('cap.confidence.noData'),
        low: t('cap.confidence.low'),
        medium: t('cap.confidence.medium'),
        high: t('cap.confidence.high'),
      },
    }),
    [t],
  )

  const header = (
    <PageHeader
      title={t('cap.title')}
      subtitle={t('cap.subtitle')}
      actions={
        <>
          <select
            value={range}
            onChange={(e) => setRange(e.target.value as CapacityPlanningRange)}
            className="rounded-lg border border-white/10 bg-[#0f151d] px-3 py-1.5 text-xs font-medium text-white outline-none transition hover:border-orange-500/40"
            aria-label={t('cap.rangeLabel')}
          >
            {rangeOptions.map((opt) => (
              <option key={opt.value} value={opt.value} className="bg-[#0f151d]">
                {opt.label}
              </option>
            ))}
          </select>
          <button
            type="button"
            disabled
            title={t('cap.exportDisabled')}
            className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40 disabled:opacity-60"
          >
            {t('cap.export')}
          </button>
          <button
            type="button"
            onClick={() => setConfigureOpen(true)}
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white transition hover:bg-white/10"
          >
            {t('cap.configure')}
          </button>
        </>
      }
    />
  )

  if (!selectedWorkspaceId) {
    return (
      <div className="space-y-6">
        {header}
        <EmptyState title={t('cap.selectWorkspace')} />
      </div>
    )
  }

  if (loading && !data) {
    return (
      <div className="space-y-6">
        {header}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-28 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
          ))}
        </div>
        <div className="h-72 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {header}

      {error ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          <span>{error}</span>
          <button
            type="button"
            onClick={() => load()}
            className="rounded-lg border border-rose-400/40 bg-rose-500/20 px-3 py-1 text-xs font-semibold"
          >
            {t('cap.retry')}
          </button>
        </div>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <CapacitySummaryCard
          title={t('cap.kpi.cpuRunway')}
          value={formatRunway(summary?.cpu_runway_months ?? null, t('cap.months'), t('cap.insufficientData'))}
          status={summary?.statuses.cpu ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.cpu ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.memoryRunway')}
          value={formatRunway(summary?.memory_runway_months ?? null, t('cap.months'), t('cap.insufficientData'))}
          status={summary?.statuses.memory ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.memory ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.storageRunway')}
          value={formatRunway(summary?.storage_runway_months ?? null, t('cap.months'), t('cap.insufficientData'))}
          status={summary?.statuses.storage ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.storage ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.costOptimization')}
          value={t('cap.costUnavailableShort')}
          status={summary?.statuses.cost ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.cost ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.riskScore')}
          value={formatRisk(summary?.capacity_risk_score ?? null, t('cap.insufficientData'))}
          status={summary?.statuses.risk ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.risk ?? 'insufficient_data')}
        />
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={(id) => setActiveTab(id as CapacityTab)} />

      {activeTab === 'overview' && (
        <div className="space-y-4">
          <CapacityHealthPanel health={health} labels={healthLabels} />

          <CapacityChartContainer
            title={t('cap.forecastTitle')}
            subtitle={t('cap.forecastDesc')}
            badge={rangeOptions.find((o) => o.value === range)?.label}
            hasData={hasForecast}
            emptyTitle={t('cap.noHistory')}
            emptyDescription={t('cap.forecastEmpty')}
          >
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={projectedForecast} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                <defs>
                  {(['cpu', 'memory', 'storage'] as const).map((k) => (
                    <linearGradient key={k} id={`cap-grad-${k}`} x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor={FORECAST_COLORS[k]} stopOpacity={0.3} />
                      <stop offset="100%" stopColor={FORECAST_COLORS[k]} stopOpacity={0} />
                    </linearGradient>
                  ))}
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
                <XAxis dataKey="label" tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                <Tooltip contentStyle={{ background: '#0f151d', border: '1px solid rgba(255,255,255,0.1)' }} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Area type="monotone" dataKey="cpu" name={t('cap.cpu')} stroke={FORECAST_COLORS.cpu} fill="url(#cap-grad-cpu)" strokeWidth={2} connectNulls dot={historicalForecast.length <= 3} />
                <Area type="monotone" dataKey="memory" name={t('cap.memory')} stroke={FORECAST_COLORS.memory} fill="url(#cap-grad-memory)" strokeWidth={2} connectNulls dot={historicalForecast.length <= 3} />
                <Area type="monotone" dataKey="storage" name={t('cap.storage')} stroke={FORECAST_COLORS.storage} fill="url(#cap-grad-storage)" strokeWidth={2} connectNulls dot={historicalForecast.length <= 3} />
              </AreaChart>
            </ResponsiveContainer>
          </CapacityChartContainer>

          <CapacityChartContainer
            title={t('cap.growthTitle')}
            subtitle={t('cap.growthDesc')}
            hasData={(data?.overview.growth_trends.length ?? 0) > 0}
            emptyTitle={t('cap.noHistory')}
          >
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={(data?.overview.growth_trends ?? []).map((g) => ({
                  metric: g.metric,
                  start: g.start_pct,
                  end: g.end_pct,
                  change: g.change_pct,
                }))}
                margin={{ top: 5, right: 10, left: 0, bottom: 5 }}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
                <XAxis dataKey="metric" tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                <Tooltip contentStyle={{ background: '#0f151d', border: '1px solid rgba(255,255,255,0.1)' }} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line type="monotone" dataKey="start" name={t('cap.growth.start')} stroke="#64748b" strokeWidth={2} dot />
                <Line type="monotone" dataKey="end" name={t('cap.growth.end')} stroke="#0ea5e9" strokeWidth={2} dot />
              </LineChart>
            </ResponsiveContainer>
          </CapacityChartContainer>

          <AdvisorSection
            advisor={data?.advisor}
            labels={{
              title: t('cap.advisorTitle'),
              disabled: t('cap.advisorDisabled'),
              findings: t('cap.advisor.findings'),
              businessImpact: t('cap.advisor.businessImpact'),
              recommendedAction: t('cap.advisor.recommendedAction'),
              confidence: t('cap.advisor.confidence'),
              dataUsed: t('cap.advisor.dataUsed'),
              samplesLabel: t('cap.advisor.samples'),
              askAi: t('cap.askAi'),
              confidenceLevels: {
                no_data: t('cap.confidence.noData'),
                low: t('cap.confidence.low'),
                medium: t('cap.confidence.medium'),
                high: t('cap.confidence.high'),
              },
            }}
            onAskAi={data?.advisor?.available ? openAi : undefined}
          />
        </div>
      )}

      {activeTab === 'resource-analysis' && (
        <div className="space-y-4">
          <TopCapacityRisksTable
            risks={topRisks}
            labels={{
              title: t('cap.risks.title'),
              host: t('cap.risks.host'),
              resource: t('cap.risks.resource'),
              utilization: t('cap.utilization'),
              trend: t('cap.risks.trend'),
              runway: t('cap.risks.runway'),
              riskLevel: t('cap.risks.riskLevel'),
              lastSample: t('cap.risks.lastSample'),
              empty: t('cap.risks.empty'),
              insufficient: t('cap.insufficientData'),
              days: t('cap.days'),
              trendLabels: {
                up: t('cap.trend.up'),
                down: t('cap.trend.down'),
                flat: t('cap.trend.flat'),
                unknown: t('cap.trend.unknown'),
              },
              riskLabels: {
                critical: t('cap.status.critical'),
                warning: t('cap.status.warning'),
                healthy: t('cap.status.healthy'),
                insufficient_data: t('cap.insufficientData'),
              },
            }}
          />

          {(data?.resource_analysis.top_cpu_consumers.length ?? 0) === 0 &&
          (data?.resource_analysis.top_memory_consumers.length ?? 0) === 0 &&
          (data?.resource_analysis.top_storage_consumers.length ?? 0) === 0 ? null : (
            <div className="grid gap-4 lg:grid-cols-3">
              <ResourceConsumersTable
                title={t('cap.topCpu')}
                consumers={data?.resource_analysis.top_cpu_consumers ?? []}
                emptyTitle={t('cap.noData')}
                valueLabel={t('cap.utilization')}
              />
              <ResourceConsumersTable
                title={t('cap.topMemory')}
                consumers={data?.resource_analysis.top_memory_consumers ?? []}
                emptyTitle={t('cap.noData')}
                valueLabel={t('cap.utilization')}
              />
              <ResourceConsumersTable
                title={t('cap.topStorage')}
                consumers={data?.resource_analysis.top_storage_consumers ?? []}
                emptyTitle={t('cap.noData')}
                valueLabel={t('cap.utilization')}
              />
            </div>
          )}
        </div>
      )}

      {activeTab === 'optimization' && (
        <div className="space-y-3">
          {(data?.optimization_insights.length ?? 0) === 0 ? (
            <EmptyState title={t('cap.optimizationEmpty')} />
          ) : (
            data?.optimization_insights.map((insight) => (
              <InsightCard
                key={insight.id}
                insight={insight}
                priorityLabel={priorityLabel(insight.priority)}
                typeLabel={insightTypeLabel(insight.type)}
                labels={{
                  severity: t('cap.insight.severity'),
                  evidence: t('cap.insight.evidence'),
                  recommendation: t('cap.insight.recommendation'),
                  operationalImpact: t('cap.insight.operationalImpact'),
                  costImpact: t('cap.insight.costImpact'),
                  costUnavailable: t('cap.costUnavailable'),
                  created: t('cap.insight.created'),
                }}
              />
            ))
          )}
        </div>
      )}

      {activeTab === 'scenarios' && (
        <div className="space-y-3">
          {scenarioTemplates.length === 0 ? (
            <EmptyState title={t('cap.scenariosEmpty')} />
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {scenarioTemplates.map((template) => (
                <ScenarioTemplateCard
                  key={template.id}
                  template={template}
                  calculated={getScenarioResult(template.id)}
                  nameLabel={scenarioName(template.name)}
                  labels={{
                    growth: t('cap.scenario.growth'),
                    horizon: t('cap.scenario.horizon'),
                    targetResource: t('cap.scenario.targetResource'),
                    calculate: t('cap.scenario.calculate'),
                    currentRunway: t('cap.scenario.currentRunway'),
                    projectedRunway: t('cap.scenario.projectedRunway'),
                    riskChange: t('cap.scenario.riskChange'),
                    impactSummary: t('cap.scenario.impactSummary'),
                    insufficient: t('cap.insufficientData'),
                    cannotCalculate: t('cap.scenario.cannotCalculate'),
                    days: t('cap.days'),
                    months: t('cap.months'),
                    resources: {
                      cpu: t('cap.cpu'),
                      memory: t('cap.memory'),
                      storage: t('cap.storage'),
                    },
                    riskChangeLabels: {
                      increased: t('cap.scenario.riskIncreased'),
                      elevated: t('cap.scenario.riskElevated'),
                      stable: t('cap.scenario.riskStable'),
                      unknown: t('cap.insufficientData'),
                      baseline: t('cap.scenario.riskBaseline'),
                    },
                  }}
                  onCalculate={handleScenarioCalculate}
                />
              ))}
            </div>
          )}
        </div>
      )}

      {activeTab === 'budget' && (
        <BudgetForecastPanel
          budget={data?.budget ?? {
            forecasted_requirements: data?.budget_planning?.forecasted_requirements ?? {
              cpu: null,
              memory: null,
              storage: null,
              timeline_days: null,
            },
            cost_estimate_available: data?.budget_planning?.cost_estimate_available ?? false,
            billing_integration_status: data?.budget_planning?.billing_integration_status ?? 'not_connected',
          }}
          labels={{
            title: t('cap.budget.forecastTitle'),
            forecastCpu: t('cap.budget.forecastCpu'),
            forecastMemory: t('cap.budget.forecastMemory'),
            forecastStorage: t('cap.budget.forecastStorage'),
            timeline: t('cap.budget.timeline'),
            costStatus: t('cap.budget.costStatus'),
            costUnavailable: t('cap.costUnavailable'),
            connectBilling: t('cap.budget.connectBilling'),
            insufficient: t('cap.insufficientData'),
            days: t('cap.days'),
            pctGrowth: t('cap.budget.pctGrowth'),
            empty: t('cap.budget.forecastEmpty'),
            emptyDesc: t('cap.budget.forecastEmptyDesc'),
          }}
          onConnectBilling={() => setConfigureOpen(true)}
        />
      )}

      {configureOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className="w-full max-w-md rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white shadow-xl">
            <h3 className="text-base font-semibold">{t('cap.configure')}</h3>
            <p className="mt-2 text-sm text-white/65">{t('cap.configureDesc')}</p>
            <button
              type="button"
              onClick={() => setConfigureOpen(false)}
              className="mt-5 rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400"
            >
              {t('cap.configureClose')}
            </button>
          </div>
        </div>
      ) : null}

      <AIAgentDrawer open={aiOpen} workspaceId={wsId} seed={aiSeed} onClose={() => setAiOpen(false)} />
    </div>
  )
}

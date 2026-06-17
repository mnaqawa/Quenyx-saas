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
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { Tabs } from '../../components/observe/Tabs'
import { CapacitySummaryCard } from '../../components/observe/capacity/CapacitySummaryCard'
import { CapacityHealthPanel } from '../../components/observe/capacity/CapacityHealthPanel'
import { CapacityChartContainer } from '../../components/observe/capacity/CapacityChartContainer'
import { ResourceConsumersTable } from '../../components/observe/capacity/ResourceConsumersTable'
import { TopCapacityRisksTable } from '../../components/observe/capacity/TopCapacityRisksTable'
import { InsightCard } from '../../components/observe/capacity/InsightCard'
import { CapacityDiagnosticsPanel } from '../../components/observe/capacity/CapacityDiagnosticsPanel'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { buildCollectingPanelProps, CollectingHistoricalDataPanel } from '../../components/observe/CollectingHistoricalDataPanel'
import { useObserveAutoRefresh } from '../../hooks/useObserveAutoRefresh'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiAgentAvailable } from '../../hooks/useAiAgentAvailable'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import type { AIAgentSeed } from '../../types/aiAgent'
import type {
  CapacityPlanningRange,
  CapacityPlanningResponse,
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

function trendDirection(change: number | null | undefined, t: (key: string) => string): string {
  if (change == null || Number.isNaN(change)) return t('cap.trend.unknown')
  if (change > 0.5) return t('cap.trend.up')
  if (change < -0.5) return t('cap.trend.down')
  return t('cap.trend.flat')
}

export default function CapacityPlanning() {
  const { t } = useLanguage()
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()

  const [range, setRange] = useState<CapacityPlanningRange>('30d')
  const [activeTab, setActiveTab] = useState<CapacityTab>('overview')
  const [data, setData] = useState<CapacityPlanningResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [exporting, setExporting] = useState(false)
  const [aiOpen, setAiOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)

  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const aiAvailable = useAiAgentAvailable(selectedWorkspaceId)

  const load = useCallback(() => {
    if (!wsId) {
      setData(null)
      setError(null)
      return Promise.resolve()
    }
    setLoading(true)
    setError(null)
    return observeService
      .getCapacityPlanning(wsId, range)
      .then((res) => setData(res))
      .catch((err: unknown) => {
        setData(null)
        setError(err instanceof Error ? err.message : t('common.errorGeneric'))
      })
      .finally(() => setLoading(false))
  }, [range, t, wsId])

  const {
    interval,
    setInterval,
    markUpdated,
    refreshNow,
    secondsAgo,
  } = useObserveAutoRefresh(() => {
    void load().then(() => markUpdated())
  }, !!wsId)

  useEffect(() => {
    void load().then(() => markUpdated())
  }, [load, markUpdated])

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
          return t('cap.insufficientHistory')
      }
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

  const handleExport = useCallback(async () => {
    if (!wsId) return
    setExporting(true)
    setExportError(null)
    try {
      const report = await observeService.exportCapacityPlanning(wsId, range)
      const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = `capacity-planning-ws${wsId}-${range}.json`
      anchor.click()
      URL.revokeObjectURL(url)
    } catch (err: unknown) {
      setExportError(err instanceof Error ? err.message : t('cap.exportError'))
    } finally {
      setExporting(false)
    }
  }, [range, t, wsId])

  const showDiagnostics = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'

  const tabs = useMemo(
    () => [
      { id: 'overview', label: t('cap.tab.overview') },
      { id: 'resource-analysis', label: t('cap.tab.forecastAnalysis') },
      { id: 'optimization', label: t('cap.tab.recommendations') },
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
  const hasCapacityData = data?.meta.data_available === true

  const historicalForecast = (data?.overview.forecast ?? []).filter((p) => !p.projected)
  const projectedForecast = data?.overview.forecast ?? []
  const hasForecast = projectedForecast.some(
    (p) => p.cpu != null || p.memory != null || p.storage != null,
  )

  const topRisks = data?.top_risks ?? data?.resource_analysis.top_risks ?? []
  const growthTrends = data?.overview.growth_trends ?? []

  const healthLabels = useMemo(
    () => ({
      title: t('cap.health.title'),
      healthStatus: t('cap.health.status'),
      riskScore: t('cap.kpi.riskScore'),
      primaryRisk: t('cap.health.primaryRisk'),
      shortestRunway: t('cap.health.shortestRunway'),
      recommendedAction: t('cap.health.recommendedAction'),
      dataConfidence: t('cap.health.dataConfidence'),
      insufficient: t('cap.insufficientHistory'),
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
          {aiAvailable ? (
            <button
              type="button"
              onClick={() => {
                setAiSeed({
                  id: Date.now(),
                  agent: 'performance_analyst',
                  question:
                    'Analyze capacity risks across this workspace: runway, top consumers, and forecast confidence.',
                  autoSend: true,
                  quick: true,
                  context: {
                    source: 'qynsight_capacity',
                    metrics: {
                      range,
                      capacity_risk_score: data?.summary?.capacity_risk_score ?? null,
                      has_capacity_data: hasCapacityData,
                    },
                  },
                })
                setAiOpen(true)
              }}
              disabled={!data}
              className="rounded-lg border border-orange-500/40 bg-orange-500/15 px-3 py-1.5 text-xs font-semibold text-orange-100 hover:bg-orange-500/25 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t('ai.action.analyzeCapacity')}
            </button>
          ) : null}
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
            disabled={!hasCapacityData || exporting}
            onClick={handleExport}
            title={!hasCapacityData ? t('cap.exportDisabled') : undefined}
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {exporting ? t('cap.exporting') : t('cap.exportJson')}
          </button>
          <ObservePageToolbar
            interval={interval}
            onIntervalChange={setInterval}
            secondsAgo={secondsAgo}
            onRefresh={() => {
              void load().then(() => markUpdated())
              refreshNow()
            }}
            refreshing={loading}
          />
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
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-28 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {header}

      {exportError ? (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
          {exportError}
        </div>
      ) : null}

      {error ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          <span>{error}</span>
          <button
            type="button"
            onClick={() => void load()}
            className="rounded-lg border border-rose-400/40 bg-rose-500/20 px-3 py-1 text-xs font-semibold"
          >
            {t('cap.retry')}
          </button>
        </div>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <CapacitySummaryCard
          title={t('cap.kpi.cpuRunway')}
          value={formatRunway(summary?.cpu_runway_months ?? null, t('cap.months'), t('cap.insufficientHistory'))}
          status={summary?.statuses.cpu ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.cpu ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.memoryRunway')}
          value={formatRunway(summary?.memory_runway_months ?? null, t('cap.months'), t('cap.insufficientHistory'))}
          status={summary?.statuses.memory ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.memory ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.storageRunway')}
          value={formatRunway(summary?.storage_runway_months ?? null, t('cap.months'), t('cap.insufficientHistory'))}
          status={summary?.statuses.storage ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.storage ?? 'insufficient_data')}
        />
        <CapacitySummaryCard
          title={t('cap.kpi.riskScore')}
          value={formatRisk(summary?.capacity_risk_score ?? null, t('cap.insufficientHistory'))}
          status={summary?.statuses.risk ?? 'insufficient_data'}
          statusLabel={statusLabel(summary?.statuses.risk ?? 'insufficient_data')}
        />
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={(id) => setActiveTab(id as CapacityTab)} />

      {activeTab === 'overview' && (
        <div className="space-y-4">
          {!hasCapacityData ? (
            <CollectingHistoricalDataPanel
              {...buildCollectingPanelProps(data?.diagnostics, data?.meta?.history_points, data?.health?.data_confidence, t)}
            />
          ) : (
            <CapacityHealthPanel health={health} labels={healthLabels} />
          )}
        </div>
      )}

      {activeTab === 'resource-analysis' && (
        <div className="space-y-4">
          {!hasCapacityData ? (
            <CollectingHistoricalDataPanel
              {...buildCollectingPanelProps(data?.diagnostics, data?.meta?.history_points, data?.health?.data_confidence, t)}
            />
          ) : (
            <>
              <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-4 text-sm text-white">
                <div className="flex flex-wrap gap-6">
                  <div>
                    <span className="text-xs text-white/50">{t('cap.forecastHorizon')}</span>
                    <p className="font-medium">{rangeOptions.find((o) => o.value === range)?.label}</p>
                  </div>
                  {growthTrends.map((g) => (
                    <div key={g.metric}>
                      <span className="text-xs text-white/50">{g.metric} {t('cap.growth.direction')}</span>
                      <p className="font-medium">{trendDirection(g.change_pct, t)}</p>
                    </div>
                  ))}
                </div>
              </div>

              <CapacityChartContainer
                title={t('cap.forecastTitle')}
                subtitle={t('cap.forecastDesc')}
                badge={rangeOptions.find((o) => o.value === range)?.label}
                hasData={hasForecast}
                emptyTitle={t('cap.noHistoryTitle')}
                emptyDescription={t('cap.noHistoryDesc')}
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
                hasData={growthTrends.length > 0}
                emptyTitle={t('cap.noHistoryTitle')}
                emptyDescription={t('cap.noHistoryDesc')}
              >
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart
                    data={growthTrends.map((g) => ({
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
                  insufficient: t('cap.insufficientHistory'),
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
                    insufficient_data: t('cap.insufficientHistory'),
                  },
                }}
              />

              {(data?.resource_analysis.top_cpu_consumers.length ?? 0) === 0 &&
              (data?.resource_analysis.top_memory_consumers.length ?? 0) === 0 &&
              (data?.resource_analysis.top_storage_consumers.length ?? 0) === 0 ? null : (
                <div className="grid min-w-0 gap-4 lg:grid-cols-3">
                  <ResourceConsumersTable
                    title={t('cap.topCpu')}
                    consumers={data?.resource_analysis.top_cpu_consumers ?? []}
                    emptyTitle={t('cap.noData')}
                    valueLabel={t('cap.utilization')}
                    hostLabel={t('cap.risks.host')}
                  />
                  <ResourceConsumersTable
                    title={t('cap.topMemory')}
                    consumers={data?.resource_analysis.top_memory_consumers ?? []}
                    emptyTitle={t('cap.noData')}
                    valueLabel={t('cap.utilization')}
                    hostLabel={t('cap.risks.host')}
                  />
                  <ResourceConsumersTable
                    title={t('cap.topStorage')}
                    consumers={data?.resource_analysis.top_storage_consumers ?? []}
                    emptyTitle={t('cap.noData')}
                    valueLabel={t('cap.utilization')}
                    hostLabel={t('cap.risks.host')}
                  />
                </div>
              )}
            </>
          )}
        </div>
      )}

      {activeTab === 'optimization' && (
        <div className="space-y-3">
          {(data?.optimization_insights.length ?? 0) === 0 ? (
            <EmptyState
              title={t('cap.optimizationEmpty')}
              description={hasCapacityData ? undefined : t('cap.noHistoryDesc')}
            />
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

      {showDiagnostics && data?.diagnostics ? (
        <CapacityDiagnosticsPanel
          diagnostics={data.diagnostics}
          labels={{
            title: t('cap.diagnostics.title'),
            historyAvailable: t('cap.diagnostics.historyAvailable'),
            totalSamples: t('cap.diagnostics.totalSamples'),
            hostsWithMetrics: t('cap.diagnostics.hostsWithMetrics'),
            oldestSample: t('cap.diagnostics.oldestSample'),
            newestSample: t('cap.diagnostics.newestSample'),
            supportedMetrics: t('cap.diagnostics.supportedMetrics'),
            insufficientReasons: t('cap.diagnostics.insufficientReasons'),
            yes: t('cap.diagnostics.yes'),
            no: t('cap.diagnostics.no'),
          }}
        />
      ) : null}

      {wsId ? (
        <AIAgentDrawer
          open={aiOpen}
          workspaceId={wsId}
          seed={aiSeed}
          onClose={() => {
            setAiOpen(false)
            setAiSeed(null)
          }}
        />
      ) : null}
    </div>
  )
}

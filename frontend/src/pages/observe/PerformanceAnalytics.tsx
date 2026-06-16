import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveServices } from '../../hooks/useObserveData'
import { observeService } from '../../services/observeService'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatCard } from '../../components/observe/StatCard'
import { Tabs } from '../../components/observe/Tabs'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import type { AIAgentSeed } from '../../types/aiAgent'
import type { ObserveServiceRow, PerformanceHistoryRange, PerformanceHistoryResponse } from '../../types/observe'
import { useLanguage } from '../../i18n/LanguageContext'
import { type MetricKind, worstStatus } from '../../utils/perfData'

const METRIC_COLORS: Record<MetricKind, string> = {
  cpu: '#0ea5e9',
  memory: '#10b981',
  disk: '#f59e0b',
  network: '#a855f7',
}

interface HostRow {
  name: string
  cpu: number | null
  memory: number | null
  disk: number | null
  network: number | null
  status: ObserveServiceRow['status']
  services: number
  lastSeenAt: string | null
}

const statusBadgeClass = (status: ObserveServiceRow['status']): string => {
  switch (status) {
    case 'critical':
      return 'border-rose-500/30 bg-rose-500/20 text-rose-200'
    case 'warning':
      return 'border-amber-500/30 bg-amber-500/20 text-amber-200'
    case 'unknown':
      return 'border-purple-500/30 bg-purple-500/20 text-purple-200'
    case 'pending':
      return 'border-sky-500/30 bg-sky-500/20 text-sky-200'
    default:
      return 'border-emerald-500/30 bg-emerald-500/20 text-emerald-200'
  }
}

const usageColor = (percent: number): string => {
  if (percent >= 90) return 'bg-rose-500'
  if (percent >= 70) return 'bg-amber-500'
  return 'bg-sky-500'
}

const formatPercent = (value: number | null): string => (value == null ? '—' : `${Math.round(value)}%`)

export default function PerformanceAnalytics() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const { selectedWorkspaceId } = useWorkspaceContext()

  const [activeTab, setActiveTab] = useState<'overview' | MetricKind>('overview')
  const [range, setRange] = useState<PerformanceHistoryRange>('24h')
  const [history, setHistory] = useState<PerformanceHistoryResponse | null>(null)
  const [historyLoading, setHistoryLoading] = useState(false)
  const [historyError, setHistoryError] = useState<string | null>(null)
  const [aiOpen, setAiOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)

  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const prefix = selectedWorkspaceId ? `ws${selectedWorkspaceId}-` : ''

  const { data, loading, error } = useObserveServices({
    workspaceId: selectedWorkspaceId ?? null,
    limit: 500,
    realDataOnly: true,
  })

  useEffect(() => {
    if (!wsId) {
      setHistory(null)
      setHistoryError(null)
      return
    }
    let cancelled = false
    setHistoryLoading(true)
    setHistoryError(null)
    observeService
      .getPerformanceHistory(wsId, range)
      .then((res) => {
        if (!cancelled) setHistory(res)
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setHistory(null)
          setHistoryError(err instanceof Error ? err.message : t('common.errorGeneric'))
        }
      })
      .finally(() => {
        if (!cancelled) setHistoryLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [range, t, wsId])

  const serviceHostMap = useMemo(() => {
    const items = data?.items ?? []
    const map = new Map<string, ObserveServiceRow[]>()
    for (const item of items) {
      const name = item.host.startsWith(prefix) ? item.host.slice(prefix.length) : item.host
      const arr = map.get(name) ?? []
      arr.push(item)
      map.set(name, arr)
    }
    return map
  }, [data?.items, prefix])

  const hosts = useMemo<HostRow[]>(() => {
    const rows = new Map<string, HostRow>()

    for (const host of history?.hosts ?? []) {
      const serviceRows = serviceHostMap.get(host.name) ?? []
      rows.set(host.name, {
        name: host.name,
        cpu: host.cpu,
        memory: host.memory,
        disk: host.disk,
        network: host.network,
        status: serviceRows.length > 0 ? worstStatus(serviceRows) : 'pending',
        services: serviceRows.length,
        lastSeenAt: host.last_seen_at,
      })
    }

    for (const [name, serviceRows] of serviceHostMap.entries()) {
      if (rows.has(name)) continue
      rows.set(name, {
        name,
        cpu: null,
        memory: null,
        disk: null,
        network: null,
        status: worstStatus(serviceRows),
        services: serviceRows.length,
        lastSeenAt: null,
      })
    }

    return [...rows.values()]
      .sort((a, b) => a.name.localeCompare(b.name))
  }, [history?.hosts, serviceHostMap])

  const averages = useMemo(() => {
    return {
      cpu: history?.latest.cpu ?? null,
      memory: history?.latest.memory ?? null,
      disk: history?.latest.disk ?? null,
      network: history?.latest.network ?? null,
    }
  }, [history?.latest])

  const series = history?.trends ?? []

  const delta = useCallback(
    (kind: MetricKind): { direction: 'up' | 'down'; value: string } | undefined => {
      const pts = series.map((s) => s[kind]).filter((v): v is number => typeof v === 'number')
      if (pts.length < 2) return undefined
      const d = pts[pts.length - 1] - pts[0]
      if (Math.abs(d) < 0.5) return undefined
      return { direction: d > 0 ? 'up' : 'down', value: `${Math.abs(Math.round(d))}%` }
    },
    [series],
  )

  const openAi = useCallback(() => {
    setAiSeed({
      id: Date.now(),
      agent: 'performance_analyst',
      question:
        'Analyze workspace performance across all hosts (CPU, memory, disk, network) and highlight the top risks and recommendations.',
      autoSend: true,
      quick: true,
      context: {
        source: 'qynsight_performance',
        metrics: {
          avg_cpu_pct: averages.cpu,
          avg_memory_pct: averages.memory,
          avg_disk_pct: averages.disk,
          avg_network_pct: averages.network,
          host_count: hosts.length,
          range,
        },
        services: hosts.map((h) => ({
          host: h.name,
          status: h.status,
          cpu_pct: h.cpu,
          memory_pct: h.memory,
          disk_pct: h.disk,
          network_pct: h.network,
        })),
      },
    })
    setAiOpen(true)
  }, [averages, hosts, range])

  const tabs = [
    { id: 'overview', label: t('perf.tab.overview') },
    { id: 'cpu', label: t('perf.tab.cpu') },
    { id: 'memory', label: t('perf.tab.memory') },
    { id: 'disk', label: t('perf.tab.disk') },
    { id: 'network', label: t('perf.tab.network') },
  ]

  const metricLabel: Record<MetricKind, string> = {
    cpu: t('perf.cpu'),
    memory: t('perf.memory'),
    disk: t('perf.disk'),
    network: t('perf.network'),
  }

  const kpiCards: Array<{ kind: MetricKind; title: string }> = [
    { kind: 'cpu', title: t('perf.kpi.cpu') },
    { kind: 'memory', title: t('perf.kpi.memory') },
    { kind: 'disk', title: t('perf.kpi.disk') },
    { kind: 'network', title: t('perf.kpi.network') },
  ]

  const rangeOptions: Array<{ value: PerformanceHistoryRange; label: string }> = [
    { value: '1h', label: t('perf.range.1h') },
    { value: '6h', label: t('perf.range.6h') },
    { value: '24h', label: t('perf.range.24h') },
    { value: '7d', label: t('perf.range.7d') },
    { value: '30d', label: t('perf.range.30d') },
  ]

  const header = (
    <PageHeader
      title={t('perf.title')}
      subtitle={t('perf.subtitle')}
      actions={
        <>
          <button
            type="button"
            onClick={openAi}
            disabled={hosts.length === 0}
            className="inline-flex items-center gap-1.5 rounded-lg border border-orange-500/40 bg-orange-500/20 px-3 py-1.5 text-xs font-semibold text-orange-100 transition hover:bg-orange-500/30 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('perf.aiAgent')}
          </button>
          <select
            value={range}
            onChange={(event) => setRange(event.target.value as PerformanceHistoryRange)}
            className="rounded-lg border border-white/10 bg-[#0f151d] px-3 py-1.5 text-xs font-medium text-white outline-none transition hover:border-orange-500/40"
            aria-label={t('perf.rangeLabel')}
          >
            {rangeOptions.map((option) => (
              <option key={option.value} value={option.value} className="bg-[#0f151d] text-white">
                {option.label}
              </option>
            ))}
          </select>
        </>
      }
    />
  )

  if (!selectedWorkspaceId) {
    return (
      <div className="space-y-6">
        {header}
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-sm text-white/60">
          {t('perf.selectWorkspace')}
        </div>
      </div>
    )
  }

  if ((loading && !data) || (historyLoading && !history)) {
    return (
      <div className="space-y-6">
        {header}
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-sm text-white/60">
          {t('common.loadingDashboard')}
        </div>
      </div>
    )
  }

  if (!historyLoading && hosts.length === 0) {
    return (
      <div className="space-y-6">
        {header}
        {error && (
          <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">{error}</div>
        )}
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-12 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full border border-white/20 bg-white/5 text-white/50">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="2" width="20" height="8" rx="2" />
              <rect x="2" y="14" width="20" height="8" rx="2" />
            </svg>
          </div>
          <h3 className="mt-4 text-sm font-semibold text-white">{t('perf.noHostsTitle')}</h3>
          <p className="mx-auto mt-1 max-w-md text-xs text-white/60">{t('perf.noHostsDesc')}</p>
          <Link
            to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`}
            className="mt-5 inline-flex rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
          >
            {t('perf.addHosts')}
          </Link>
        </div>
      </div>
    )
  }

  const shownMetrics: MetricKind[] = activeTab === 'overview' ? ['cpu', 'memory', 'disk', 'network'] : [activeTab]
  const hasSamples = series.some((s) => shownMetrics.some((k) => typeof s[k] === 'number'))
  const showTrendDots = series.length <= 2

  return (
    <div className="space-y-6">
      {header}

      {error && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-xs text-amber-100">
          {error}. {t('perf.showingLast')}
        </div>
      )}
      {historyError && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-2 text-xs text-rose-100">
          {historyError}
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {kpiCards.map(({ kind, title }) => (
          <StatCard
            key={kind}
            title={title}
            value={formatPercent(averages[kind])}
            detail={`${history?.host_count ?? hosts.length} ${t('perf.hosts')}`}
            trend={averages[kind] != null ? delta(kind) && { ...delta(kind)!, label: t('perf.vsRangeStart') } : undefined}
            percentage={averages[kind] ?? undefined}
          />
        ))}
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={(id) => setActiveTab(id as 'overview' | MetricKind)} />

      {/* Historical trend chart */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">{t('perf.trendTitle')}</h3>
          <span className="rounded bg-orange-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase text-orange-100">
            {rangeOptions.find((option) => option.value === range)?.label}
          </span>
        </div>
        <p className="mb-3 text-xs text-white/50">{t('perf.trendDesc')}</p>
        <div className="h-[300px] w-full">
          {hasSamples ? (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={series} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                <defs>
                  {shownMetrics.map((k) => (
                    <linearGradient key={k} id={`grad-${k}`} x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor={METRIC_COLORS[k]} stopOpacity={0.35} />
                      <stop offset="100%" stopColor={METRIC_COLORS[k]} stopOpacity={0} />
                    </linearGradient>
                  ))}
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
                <XAxis dataKey="label" tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                <Tooltip
                  contentStyle={{ background: '#0f151d', border: '1px solid rgba(255,255,255,0.1)' }}
                  labelStyle={{ color: 'rgba(255,255,255,0.8)' }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                {shownMetrics.map((k) => (
                  <Area
                    key={k}
                    type="monotone"
                    dataKey={k}
                    name={metricLabel[k]}
                    stroke={METRIC_COLORS[k]}
                    fill={`url(#grad-${k})`}
                    strokeWidth={2}
                    connectNulls
                    dot={showTrendDots ? { r: 3, strokeWidth: 2 } : false}
                    activeDot={{ r: 4 }}
                  />
                ))}
              </AreaChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-full items-center justify-center text-xs text-white/40">
              {historyLoading ? t('common.loadingDashboard') : t('perf.noHistory')}
            </div>
          )}
        </div>
      </div>

      {/* Per-metric ranking (metric tabs only) */}
      {activeTab !== 'overview' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-1 text-sm font-semibold">
            {t('perf.topHostsBy')} {metricLabel[activeTab]}
          </h3>
          <p className="mb-4 text-xs text-white/50">{t('perf.rankingDesc')}</p>
          {(() => {
            const ranked = hosts
              .filter((h) => typeof h[activeTab] === 'number')
              .sort((a, b) => ((b[activeTab] as number | null) ?? 0) - ((a[activeTab] as number | null) ?? 0))
            if (ranked.length === 0) {
              return (
                <div className="rounded-lg border border-white/10 bg-white/5 px-4 py-6 text-center text-xs text-white/60">
                  {t('perf.noMetricData')} {metricLabel[activeTab]}.
                </div>
              )
            }
            return (
              <div className="space-y-3">
                {ranked.map((h) => {
                  const pct = (h[activeTab] as number | null) ?? 0
                  return (
                    <button
                      key={h.name}
                      type="button"
                      onClick={() =>
                        navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(h.name)}`)
                      }
                      className="block w-full text-left"
                    >
                      <div className="mb-1 flex items-center justify-between text-xs">
                        <span className="font-medium text-white">{h.name}</span>
                        <span className="tabular-nums text-white/70">{formatPercent(pct)}</span>
                      </div>
                      <div className="h-2 w-full rounded-full bg-white/5">
                        <div className={`h-2 rounded-full ${usageColor(pct)}`} style={{ width: `${Math.min(pct, 100)}%` }} />
                      </div>
                    </button>
                  )
                })}
              </div>
            )
          })()}
        </div>
      )}

      {/* Server performance table */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <div className="mb-4">
          <h3 className="text-sm font-semibold">{t('perf.serverTitle')}</h3>
          <p className="text-xs text-white/50">{t('perf.serverDesc')}</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="border-b border-white/10 text-[11px] uppercase tracking-wider text-white/50">
                <th className="pb-2 pr-4 font-medium">{t('perf.host')}</th>
                <th className="pb-2 pr-4 font-medium">{t('perf.cpu')}</th>
                <th className="pb-2 pr-4 font-medium">{t('perf.memory')}</th>
                <th className="pb-2 pr-4 font-medium">{t('perf.disk')}</th>
                <th className="pb-2 pr-4 font-medium">{t('perf.network')}</th>
                <th className="pb-2 pr-4 font-medium">{t('perf.status')}</th>
              </tr>
            </thead>
            <tbody>
              {hosts.map((h) => {
                const cell = (m: number | null) =>
                  m != null ? formatPercent(m) : <span className="text-white/30">{t('perf.notMonitored')}</span>
                return (
                  <tr
                    key={h.name}
                    onClick={() =>
                      navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(h.name)}`)
                    }
                    className="cursor-pointer border-b border-white/5 transition hover:bg-white/5"
                  >
                    <td className="py-2.5 pr-4">
                      <div className="font-medium text-white">{h.name}</div>
                      <div className="text-[10px] text-white/45">
                        {h.services} {t('perf.services')}
                      </div>
                    </td>
                    <td className="py-2.5 pr-4 tabular-nums text-white/80">{cell(h.cpu)}</td>
                    <td className="py-2.5 pr-4 tabular-nums text-white/80">{cell(h.memory)}</td>
                    <td className="py-2.5 pr-4 tabular-nums text-white/80">{cell(h.disk)}</td>
                    <td className="py-2.5 pr-4 tabular-nums text-white/80">{cell(h.network)}</td>
                    <td className="py-2.5 pr-4">
                      <span
                        className={`rounded-full border px-2 py-0.5 text-[10px] font-medium uppercase ${statusBadgeClass(h.status)}`}
                      >
                        {h.status}
                      </span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      <AIAgentDrawer open={aiOpen} workspaceId={wsId} seed={aiSeed} onClose={() => setAiOpen(false)} />
    </div>
  )
}

import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  Area,
  AreaChart,
  Line,
  LineChart,
  ResponsiveContainer,
  XAxis,
  YAxis,
  Tooltip,
  CartesianGrid,
  Legend,
} from 'recharts'
import { useObserveWorkspaceId } from '../../hooks/useObserveWorkspaceId'
import { useObserveServices } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { useObserveAutoRefresh } from '../../hooks/useObserveAutoRefresh'
import { useLanguage } from '../../i18n/LanguageContext'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import type { AIAgentSeed } from '../../types/aiAgent'
import { useAiAgentAvailable } from '../../hooks/useAiAgentAvailable'
import { observeService } from '../../services/observeService'
import type { ObserveServiceRow, RealTimeMetrics, SystemInfo } from '../../types/observe'
import { pickHostMetric } from '../../utils/perfData'

const MAX_POINTS = 120

const svcBadgeClass = (status: ObserveServiceRow['status']): string => {
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

function MetricCard({
  title,
  value,
  detail,
  percentage,
  icon,
  valueSuffix = '',
}: {
  title: string
  value: string | number
  detail: string
  percentage?: number
  icon: React.ReactNode
  valueSuffix?: string
}) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <div className="flex items-start justify-between">
        <div className="flex-1 min-w-0">
          <p className="text-xs font-medium text-white/50 uppercase tracking-wider">{title}</p>
          <p className="mt-1 text-2xl font-bold tabular-nums">
            {value}
            {valueSuffix}
          </p>
          {detail && (
            <p className="mt-1 text-xs text-white/60 truncate" title={detail}>
              {detail}
            </p>
          )}
          {percentage !== undefined && (
            <div className="mt-3 h-2 w-full rounded-full bg-white/5">
              <div
                className="h-2 rounded-full bg-sky-500 transition-all duration-300"
                style={{ width: `${Math.min(Math.max(percentage, 0), 100)}%` }}
              />
            </div>
          )}
        </div>
        <div className="ml-3 flex-shrink-0 text-emerald-400/80">{icon}</div>
      </div>
    </div>
  )
}

// Icons for metric cards
const Icons = {
  cpu: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <rect x="4" y="4" width="16" height="16" rx="2" ry="2" />
      <rect x="9" y="9" width="6" height="6" />
      <line x1="9" y1="1" x2="9" y2="4" />
      <line x1="15" y1="1" x2="15" y2="4" />
      <line x1="9" y1="20" x2="9" y2="23" />
      <line x1="15" y1="20" x2="15" y2="23" />
      <line x1="20" y1="9" x2="23" y2="9" />
      <line x1="20" y1="14" x2="23" y2="14" />
      <line x1="1" y1="9" x2="4" y2="9" />
      <line x1="1" y1="14" x2="4" y2="14" />
    </svg>
  ),
  memory: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <rect x="2" y="6" width="20" height="12" rx="1" />
      <line x1="6" y1="10" x2="6" y2="14" />
      <line x1="10" y1="10" x2="10" y2="14" />
      <line x1="14" y1="10" x2="14" y2="14" />
      <line x1="18" y1="10" x2="18" y2="14" />
    </svg>
  ),
  disk: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <circle cx="12" cy="12" r="10" />
      <circle cx="12" cy="12" r="6" />
      <circle cx="12" cy="12" r="2" />
    </svg>
  ),
  network: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <circle cx="12" cy="5" r="3" />
      <line x1="12" y1="8" x2="12" y2="12" />
      <line x1="8" y1="12" x2="16" y2="12" />
      <line x1="7" y1="21" x2="7" y2="12" />
      <line x1="12" y1="21" x2="12" y2="12" />
      <line x1="17" y1="21" x2="17" y2="12" />
    </svg>
  ),
  load: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M3 12h2v6H3v-6zM9 8h2v10H9V8zM15 14h2v4h-2v-4zM21 10h2v8h-2v-8z" />
    </svg>
  ),
}

export default function RealTimeMonitoring() {
  const navigate = useNavigate()
  const { t } = useLanguage()
  const selectedWorkspaceId = useObserveWorkspaceId()

  const [isLive, setIsLive] = useState(true)
  const [selectedHost, setSelectedHost] = useState<string>('')
  const [refreshKey, setRefreshKey] = useState(0)
  const [metrics, setMetrics] = useState<RealTimeMetrics | null>(null)
  const [systemInfo, setSystemInfo] = useState<SystemInfo | null>(null)
  const [thresholds, setThresholds] = useState<Array<{ metric: string; warning: string; critical: string }>>([])
  const [timeSeries, setTimeSeries] = useState<Array<{ time: string; cpu: number; memory: number; disk: number; network: number }>>([])
  const [metricsError, setMetricsError] = useState<string | null>(null)
  const [hostList, setHostList] = useState<Array<{ name: string; address: string }>>([])
  const [targetsLoaded, setTargetsLoaded] = useState(false)
  const [targetsError, setTargetsError] = useState<string | null>(null)
  const [aiDrawerOpen, setAiDrawerOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)

  // Reset workspace-scoped state when workspace changes so we never show another workspace's data
  useEffect(() => {
    setMetrics(null)
    setSystemInfo(null)
    setTimeSeries([])
    setHostList([])
    setSelectedHost('')
    setMetricsError(null)
    setTargetsError(null)
    setTargetsLoaded(false)
  }, [selectedWorkspaceId])

  const { data: servicesData, loading: kpisLoading, refreshing: kpisRefreshing, error: kpisError } = useObserveServices({
    workspaceId: selectedWorkspaceId ?? null,
    limit: 500,
    refreshKey,
  })

  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const aiAvailable = useAiAgentAvailable(selectedWorkspaceId)
  const prefix = selectedWorkspaceId ? `ws${selectedWorkspaceId}-` : ''

  const { hostTotals, serviceTotals, problems, lastPollAt } = useMemo(() => {
    if (!servicesData) {
      return {
        hostTotals: { up: 0, down: 0, unreachable: 0, pending: 0 },
        serviceTotals: { ok: 0, warning: 0, critical: 0, unknown: 0, pending: 0, unreachable: 0 },
        problems: 0,
        lastPollAt: null as string | null,
      }
    }
    const items = servicesData.items ?? []
    if (!selectedHost) {
      return {
        hostTotals: servicesData.hostTotals ?? { up: 0, down: 0, unreachable: 0, pending: 0 },
        serviceTotals: servicesData.serviceTotals ?? { ok: 0, warning: 0, critical: 0, unknown: 0, pending: 0, unreachable: 0 },
        problems:
          (servicesData.serviceTotals?.warning ?? 0) +
          (servicesData.serviceTotals?.critical ?? 0) +
          (servicesData.serviceTotals?.unknown ?? 0) +
          (servicesData.serviceTotals?.unreachable ?? 0),
        lastPollAt: servicesData.last_poll_at ?? null,
      }
    }
    const hostKey = prefix ? `${prefix}${selectedHost}` : selectedHost
    const filtered = items.filter((item: { host: string }) => item.host === hostKey || item.host === selectedHost || item.host.endsWith(selectedHost))
    const ok = filtered.filter((i: { status: string }) => i.status === 'ok').length
    const warning = filtered.filter((i: { status: string }) => i.status === 'warning').length
    const critical = filtered.filter((i: { status: string }) => i.status === 'critical').length
    const unknown = filtered.filter((i: { status: string }) => i.status === 'unknown').length
    const pending = filtered.filter((i: { status: string }) => i.status === 'pending').length
    const unreachable = filtered.filter((i: { status: string }) => i.status === 'unreachable').length
    const serviceTotalsFiltered = { ok, warning, critical, unknown, pending, unreachable: unreachable ?? 0 }
    return {
      hostTotals: { up: filtered.length ? 1 : 0, down: 0, unreachable: 0, pending: 0 },
      serviceTotals: serviceTotalsFiltered,
      problems: warning + critical + unknown + unreachable,
      lastPollAt: servicesData.last_poll_at ?? null,
    }
  }, [servicesData, selectedHost, prefix])

  // Services that belong to the currently selected host (real data).
  const hostServices = useMemo<ObserveServiceRow[]>(() => {
    if (!selectedHost || !servicesData?.items) return []
    const hostKey = prefix ? `${prefix}${selectedHost}` : selectedHost
    return servicesData.items.filter(
      (item) => item.host === hostKey || item.host === selectedHost || item.host.endsWith(selectedHost),
    )
  }, [servicesData?.items, selectedHost, prefix])

  // Per-host CPU / memory / disk / network derived from the host's own service checks.
  const perHost = useMemo(
    () => ({
      cpu: pickHostMetric(hostServices, 'cpu'),
      memory: pickHostMetric(hostServices, 'memory'),
      disk: pickHostMetric(hostServices, 'disk'),
      network: pickHostMetric(hostServices, 'network'),
    }),
    [hostServices],
  )

  const hasAnyHostMetric = !!(perHost.cpu || perHost.memory || perHost.disk || perHost.network)

  // Fetch host list for dropdown (real data: only targets added in this workspace)
  useEffect(() => {
    if (!wsId) {
      setHostList([])
      setSelectedHost('')
      setTargetsLoaded(true)
      return
    }
    setTargetsLoaded(false)
    setTargetsError(null)
    observeService
      .getTargets(wsId)
      .then((list) => {
        const arr = Array.isArray(list) ? list : (list as { targets?: Array<{ name: string; address: string }> })?.targets ?? []
        setHostList(arr)
        setSelectedHost((prev) => {
          if (arr.length === 0) return ''
          return arr.some((h) => h.name === prev) ? prev : arr[0].name
        })
        setTargetsLoaded(true)
      })
      .catch((e) => {
        setHostList([])
        setTargetsError(e instanceof Error ? e.message : 'Failed to load hosts')
        setTargetsLoaded(true)
      })
  }, [wsId, refreshKey])

  // Fetch thresholds once when workspace is set
  useEffect(() => {
    if (!wsId) {
      setThresholds([])
      return
    }
    observeService
      .getPerformanceThresholds(wsId)
      .then((data) => setThresholds(Array.isArray(data) ? data : []))
      .catch(() => setThresholds([]))
  }, [wsId])

  const fetchMetrics = useCallback(async () => {
    if (!wsId) return
    setMetricsError(null)
    try {
      const [metricsRes, infoRes] = await Promise.all([
        observeService.getRealTimeMetrics(wsId),
        observeService.getSystemInfo(wsId),
      ])
      setMetrics(metricsRes && typeof metricsRes === 'object' ? metricsRes : null)
      setSystemInfo(infoRes && typeof infoRes === 'object' ? infoRes : null)
      if (metricsRes && typeof metricsRes === 'object') {
        const m = metricsRes as RealTimeMetrics
        const now = new Date()
        const timeStr = now.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' })
        setTimeSeries((prev) => {
          const next = [
            ...prev,
            {
              time: timeStr,
              cpu: m.cpu?.value ?? 0,
              memory: m.memory?.value ?? 0,
              disk: m.diskIO?.value ?? 0,
              network: m.network?.value ?? 0,
            },
          ]
          return next.length > MAX_POINTS ? next.slice(-MAX_POINTS) : next
        })
      }
    } catch (e) {
      setMetricsError(e instanceof Error ? e.message : 'Failed to load system metrics')
    }
  }, [wsId])

  const refreshAll = useCallback(() => {
    setRefreshKey((k) => k + 1)
    void fetchMetrics()
  }, [fetchMetrics])

  const {
    interval,
    setInterval,
    markUpdated,
    refreshNow,
    secondsAgo,
  } = useObserveAutoRefresh(refreshAll, !!selectedWorkspaceId && isLive)

  useEffect(() => {
    if (!kpisLoading && servicesData) markUpdated()
  }, [kpisLoading, servicesData, refreshKey, markUpdated])

  useEffect(() => {
    if (wsId && isLive) void fetchMetrics()
  }, [wsId, refreshKey, isLive, fetchMetrics])

  const totalServices =
    serviceTotals.ok +
    serviceTotals.warning +
    serviceTotals.critical +
    serviceTotals.unknown +
    serviceTotals.pending +
    (serviceTotals.unreachable ?? 0)

  const m = metrics
  const cpuVal = m?.cpu?.value ?? 0
  const memVal = m?.memory?.value ?? 0
  const diskVal = m?.diskIO?.value ?? 0
  const netVal = m?.network?.value ?? 0
  const loadStr = systemInfo?.loadAverage ?? ''
  const load1m = loadStr ? parseFloat(loadStr.split(',')[0]?.trim() || '0') : null

  const openAiAgent = useCallback((host?: string) => {
    setAiDrawerOpen(true)
    if (host) {
      setAiSeed({
        id: Date.now(),
        agent: 'anomaly_detector',
        question: `Analyze host "${host}" and detect anomalies or unusual behaviour across its metrics.`,
        autoSend: true,
        quick: true,
        context: {
          source: 'qynsight_realtime',
          host,
          metrics: {
            cpu_pct: cpuVal,
            memory_pct: memVal,
            disk_pct: diskVal,
            network_pct: netVal,
            load_1m: load1m,
          },
          services: [
            { status: 'ok', count: serviceTotals.ok },
            { status: 'warning', count: serviceTotals.warning },
            { status: 'critical', count: serviceTotals.critical },
            { status: 'unknown', count: serviceTotals.unknown },
            { status: 'pending', count: serviceTotals.pending },
          ],
        },
      })
    }
  }, [cpuVal, memVal, diskVal, netVal, load1m, serviceTotals])

  if (kpisLoading && !hostTotals.up && !serviceTotals.ok && totalServices === 0 && !targetsLoaded) {
    return (
      <div className="flex items-center justify-center py-24">
        <div className="text-sm text-white/60">{t('rtm.loading')}</div>
      </div>
    )
  }

  // Targets API failed — do not pretend there are no hosts
  if (selectedWorkspaceId && targetsLoaded && targetsError) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('rtm.title')} subtitle={t('rtm.subtitle')} />
        <div className="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-6 text-center">
          <p className="text-sm font-medium text-rose-200">{t('observe.error.services')}</p>
          <p className="mt-1 text-xs text-rose-200/70">{targetsError}</p>
          <button
            type="button"
            onClick={() => setRefreshKey((k) => k + 1)}
            className="mt-4 rounded-lg bg-rose-500/30 px-4 py-2 text-sm font-medium text-rose-100 hover:bg-rose-500/40"
          >
            {t('observe.loadError.retry')}
          </button>
        </div>
      </div>
    )
  }

  // No targets in this workspace: show empty state and ask user to add hosts first (real data only)
  if (selectedWorkspaceId && targetsLoaded && hostList.length === 0) {
    return (
      <div className="space-y-6">
        <PageHeader
        title={t('rtm.title')}
        subtitle={t('rtm.subtitle')}
        />
        <div className="flex flex-col items-center justify-center rounded-2xl border border-white/10 bg-[#0f151d] py-20 px-6 text-center">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-white/20 bg-white/5 text-white/50">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <rect x="2" y="2" width="20" height="8" rx="2" ry="2" />
              <rect x="2" y="14" width="20" height="8" rx="2" ry="2" />
              <line x1="6" y1="6" x2="6.01" y2="6" />
              <line x1="6" y1="18" x2="6.01" y2="18" />
              <line x1="18" y1="6" x2="18.01" y2="6" />
              <line x1="18" y1="18" x2="18.01" y2="18" />
            </svg>
          </div>
          <h2 className="mt-6 text-lg font-semibold text-white">{t('rtm.noHostsTitle')}</h2>
          <p className="mt-2 max-w-sm text-sm text-white/60">
            {t('rtm.noHostsDesc')}
          </p>
          <div className="mt-6 rounded-lg border border-white/10 bg-white/5 p-4 text-left">
            <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-white/70">{t('rtm.gettingStarted')}</p>
            <ol className="space-y-2 text-sm text-white/80">
              <li className="flex items-center gap-2">
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-500/30 text-xs font-bold text-sky-200">1</span>
                {t('rtm.step1')}
              </li>
              <li className="flex items-center gap-2">
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white/70">2</span>
                {t('rtm.step2')}
              </li>
              <li className="flex items-center gap-2">
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white/70">3</span>
                {t('rtm.step3')}
              </li>
            </ol>
          </div>
          <Link
            to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`}
            className="mt-6 inline-flex items-center gap-2 rounded-full bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-400"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 5v14M5 12h14" />
            </svg>
            {t('rtm.goToHosts')}
          </Link>
          <p className="mt-4 text-[10px] text-white/40">
            {t('rtm.emptyStateFootnote')
              .replace(
                '{mapLink}',
                selectedWorkspaceId
                  ? t('nav.qynsight.infrastructureMap')
                  : t('nav.qynsight.infrastructureMap'),
              )
              .replace('{integrationsLink}', t('nav.integrations'))}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('rtm.title')}
        subtitle={t('rtm.subtitle')}
        actions={
          <div className="flex items-center gap-2">
            {aiAvailable ? (
              <button
                type="button"
                onClick={() => openAiAgent()}
                className="inline-flex items-center gap-1.5 rounded-lg border border-orange-500/40 bg-orange-500/20 px-3 py-1.5 text-xs font-semibold text-orange-100 hover:bg-orange-500/30 focus:outline-none focus:ring-2 focus:ring-orange-500/40"
              >
                {t('ai.action.analyzePerformance')}
              </button>
            ) : null}
            <select
              value={hostList.length === 0 ? '' : selectedHost}
              onChange={(e) => setSelectedHost(e.target.value)}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50 min-w-[140px]"
              title={t('rtm.selectHost')}
              disabled={hostList.length === 0}
            >
              {hostList.map((h) => (
                <option key={h.name} value={h.name} className="bg-slate-900 text-white">
                  {h.name}
                </option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => setIsLive(!isLive)}
              className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase transition-colors ${
                isLive
                  ? 'bg-rose-500/20 text-rose-200 border border-rose-500/30 hover:bg-rose-500/30'
                  : 'bg-white/10 text-white/70 border border-white/10 hover:bg-white/20'
              }`}
              aria-pressed={isLive}
              title={isLive ? t('rtm.liveToggleTitle') : t('rtm.pausedToggleTitle')}
            >
              <span className={`h-1.5 w-1.5 rounded-full ${isLive ? 'bg-rose-400 animate-pulse' : 'bg-white/50'}`} />
              {isLive ? t('rtm.live') : t('rtm.paused')}
            </button>
            <ObservePageToolbar
              interval={interval}
              onIntervalChange={setInterval}
              secondsAgo={secondsAgo}
              onRefresh={() => {
                refreshAll()
                refreshNow()
              }}
              refreshing={kpisLoading || kpisRefreshing}
              onSettings={
                selectedWorkspaceId
                  ? () => navigate(`/app/workspaces/${selectedWorkspaceId}/observe/targets`)
                  : undefined
              }
            />
          </div>
        }
      />

      {kpisError && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100" role="alert">
          {kpisError}
          {kpisError === 'Unauthorized' && (
            <p className="mt-2 text-xs text-rose-100/80">
              {t('rtm.unauthorizedHelp')}
            </p>
          )}
        </div>
      )}

      {metricsError && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
          {t('rtm.systemMetricsStale').replace('{error}', metricsError)}
        </div>
      )}

      <div className="flex items-center gap-2 rounded-lg border border-sky-500/20 bg-sky-500/5 px-3 py-2">
        <span className="text-xs font-medium text-sky-200">{t('rtm.monitoringServer')}</span>
        <span className="text-[10px] text-white/50" title={t('rtm.systemInfoDesc')}>
          {t('rtm.monitoringServerHint')}
        </span>
      </div>
      <div className="grid gap-4 md:grid-cols-5">
        <MetricCard
          title={t('rtm.metric.cpu')}
          value={m ? `${cpuVal}%` : '—'}
          detail={m?.cpu ? `${m.cpu.cores} • ${m.cpu.frequency}` : t('rtm.enableLiveMetrics')}
          percentage={cpuVal}
          icon={Icons.cpu}
        />
        <MetricCard
          title={t('rtm.metric.memory')}
          value={m ? `${memVal}%` : '—'}
          detail={m?.memory ? `${m.memory.used} / ${m.memory.total}` : '—'}
          percentage={memVal}
          icon={Icons.memory}
        />
        <MetricCard
          title={t('rtm.metric.disk')}
          value={m ? `${diskVal}%` : '—'}
          detail={m?.diskIO ? `${m.diskIO.type} • used` : '—'}
          percentage={diskVal}
          icon={Icons.disk}
        />
        <MetricCard
          title={t('rtm.metric.network')}
          value={m ? `${netVal}%` : '—'}
          detail={m?.network ? `${m.network.speed} • ${m.network.type}` : '—'}
          percentage={netVal}
          icon={Icons.network}
        />
        <MetricCard
          title={t('rtm.metric.load')}
          value={load1m != null && !Number.isNaN(load1m) ? load1m.toFixed(2) : '—'}
          detail={loadStr ? `1m, 5m, 15m: ${loadStr}` : t('rtm.enableLiveMetrics')}
          icon={Icons.load}
        />
      </div>

      {/* Charts – live time-series when Live is on */}
      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-3 flex items-center gap-2">
            <h3 className="text-sm font-semibold">{t('rtm.systemPerformance')}</h3>
            {isLive && (
              <span className="rounded bg-rose-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-200">
                {t('rtm.live')}
              </span>
            )}
          </div>
          <p className="mb-3 text-xs text-white/50">Monitoring server: CPU, Memory, Disk (real-time).</p>
          <div className="h-[220px] w-full">
            {timeSeries.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={timeSeries} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
                  <XAxis dataKey="time" tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                  <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                  <Tooltip
                    contentStyle={{ background: '#0f151d', border: '1px solid rgba(255,255,255,0.1)' }}
                    labelStyle={{ color: 'rgba(255,255,255,0.8)' }}
                  />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line type="monotone" dataKey="cpu" stroke="#0ea5e9" name="CPU %" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="memory" stroke="#10b981" name="Memory %" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="disk" stroke="#f59e0b" name="Disk %" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex h-full items-center justify-center text-xs text-white/40">
                {isLive ? t('rtm.collectingSamples') : t('rtm.enableLiveHistory')}
              </div>
            )}
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-3 flex items-center gap-2">
            <h3 className="text-sm font-semibold">Network Activity</h3>
            {isLive && (
              <span className="rounded bg-rose-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-200">
                Live
              </span>
            )}
          </div>
          <p className="mb-3 text-xs text-white/50">Monitoring server: network utilization (real-time).</p>
          <div className="h-[220px] w-full">
            {timeSeries.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={timeSeries} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <defs>
                    <linearGradient id="networkGrad" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#0ea5e9" stopOpacity={0.4} />
                      <stop offset="100%" stopColor="#0ea5e9" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
                  <XAxis dataKey="time" tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                  <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} stroke="rgba(255,255,255,0.5)" />
                  <Tooltip
                    contentStyle={{ background: '#0f151d', border: '1px solid rgba(255,255,255,0.1)' }}
                    labelStyle={{ color: 'rgba(255,255,255,0.8)' }}
                  />
                  <Area type="monotone" dataKey="network" stroke="#0ea5e9" fill="url(#networkGrad)" name="Network %" strokeWidth={2} />
                </AreaChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex h-full items-center justify-center text-xs text-white/40">
                {isLive ? t('rtm.collectingSamples') : t('rtm.enableLiveHistory')}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Per-host metrics – derived from the selected host's own service checks (real data) */}
      {selectedHost && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-sm font-semibold">{t('rtm.perHostMetrics')}</h3>
              <p className="mt-1 text-xs text-white/60">
                {t('rtm.perHostMetricsDesc').replace('{host}', selectedHost)}
              </p>
            </div>
            {aiAvailable ? (
              <button
                type="button"
                onClick={() => openAiAgent(selectedHost)}
                className="rounded-lg border border-orange-500/40 bg-orange-500/15 px-3 py-1.5 text-xs font-semibold text-orange-100 hover:bg-orange-500/25"
              >
                {t('ai.action.analyzePerformance')}
              </button>
            ) : null}
          </div>

          {hasAnyHostMetric ? (
            <div className="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
              {([
                { kind: 'cpu', title: t('rtm.metric.cpu'), metric: perHost.cpu, icon: Icons.cpu },
                { kind: 'memory', title: t('rtm.metric.memory'), metric: perHost.memory, icon: Icons.memory },
                { kind: 'disk', title: t('rtm.metric.disk'), metric: perHost.disk, icon: Icons.disk },
                { kind: 'network', title: t('rtm.metric.network'), metric: perHost.network, icon: Icons.network },
              ] as const).map(({ kind, title, metric, icon }) => (
                <MetricCard
                  key={kind}
                  title={title}
                  value={metric ? (metric.display || '—') : '—'}
                  detail={
                    metric
                      ? `${metric.service} • ${metric.status.toUpperCase()}`
                      : t('rtm.notMonitored')
                  }
                  percentage={metric?.percent ?? undefined}
                  icon={icon}
                />
              ))}
            </div>
          ) : (
            <div className="mt-4 rounded-lg border border-white/10 bg-white/5 px-4 py-6 text-center text-xs text-white/60">
              {t('rtm.noHostMetrics')
                .replace('{host}', selectedHost)
                .replace('{hostsLink}', '')}
              {selectedWorkspaceId ? (
                <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`} className="text-sky-300 hover:underline">
                  {t('rtm.hostsLink')}
                </Link>
              ) : (
                t('rtm.hostsLink')
              )}
            </div>
          )}

          {hostServices.length > 0 && (
            <div className="mt-4">
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/50">
                {t('rtm.serviceChecksFor').replace('{host}', selectedHost)}
              </p>
              <div className="overflow-hidden rounded-lg border border-white/10">
                {hostServices.map((svc, index) => {
                  return (
                    <button
                      key={`${svc.service}-${index}`}
                      type="button"
                      onClick={() =>
                        selectedWorkspaceId &&
                        navigate(
                          `/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(selectedHost)}`,
                        )
                      }
                      className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-left transition hover:bg-white/5 ${
                        index !== hostServices.length - 1 ? 'border-b border-white/5' : ''
                      }`}
                    >
                      <div className="min-w-0 flex-1">
                        <span className="block truncate text-xs font-medium text-white">{svc.service}</span>
                        {svc.info && <span className="block truncate text-[10px] text-white/45">{svc.info}</span>}
                      </div>
                      <span
                        className={`shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-medium uppercase ${svcBadgeClass(
                          svc.status,
                        )}`}
                      >
                        {svc.status}
                      </span>
                    </button>
                  )
                })}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Observe status + KPIs (filtered by selected host when set) */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-2 text-sm font-semibold">{t('rtm.observeStatus')}</h3>
        {selectedHost && (
          <p className="mb-2 text-xs text-sky-300">
            {t('rtm.showingDataFor')} <strong>{selectedHost}</strong>
          </p>
        )}
        <p className="text-xs text-white/60">
          {t('rtm.observeStatusSummary')
            .replace('{up}', String(hostTotals.up))
            .replace('{ok}', String(serviceTotals.ok))
            .replace('{problems}', String(problems))
            .replace('{pending}', String(serviceTotals.pending))
            .replace('{lastPoll}', lastPollAt ? new Date(lastPollAt).toLocaleString() : '—')}{' '}
          {selectedWorkspaceId && (
            <>
              <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`} className="text-sky-300 hover:underline">
                {t('rtm.hostsLink')}
              </Link>
              {' · '}
              <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/services`} className="text-sky-300 hover:underline">
                {t('rtm.serviceChecksLink')}
              </Link>
            </>
          )}
        </p>
      </div>

      {/* Bottom row: System Information (monitoring server), Performance Thresholds, Quick Actions */}
      <div className="grid gap-4 md:grid-cols-3">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-1 text-xs font-semibold text-white/70 uppercase tracking-wider">{t('rtm.systemInfo')}</h3>
          <p className="mb-3 text-[10px] text-white/50">{t('rtm.systemInfoDesc')}</p>
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">{t('rtm.field.hostname')}</dt>
              <dd className="font-mono text-right text-xs truncate" title={systemInfo?.hostname ?? '—'}>
                {systemInfo?.hostname ?? '—'}
              </dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">{t('rtm.field.os')}</dt>
              <dd className="font-mono text-right text-xs truncate" title={systemInfo?.os ?? '—'}>
                {systemInfo?.os ?? '—'}
              </dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">{t('rtm.field.kernel')}</dt>
              <dd className="font-mono text-right text-xs truncate" title={systemInfo?.kernel ?? '—'}>
                {systemInfo?.kernel ?? '—'}
              </dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">{t('rtm.field.uptime')}</dt>
              <dd className="font-mono text-right text-xs">{systemInfo?.uptime ?? '—'}</dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">{t('rtm.field.loadAverage')}</dt>
              <dd className="font-mono text-right text-xs">{systemInfo?.loadAverage ?? '—'}</dd>
            </div>
          </dl>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">{t('rtm.performanceThresholds')}</h3>
          <p className="text-xs text-white/50 mb-3">{t('rtm.thresholdsHint')}</p>
          <dl className="space-y-1.5 text-xs">
            {thresholds.length > 0
              ? thresholds.flatMap((th) => [
                  <div key={`${th.metric}-w`} className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdWarning').replace('{metric}', th.metric)}</dt>
                    <dd className="text-amber-400">{th.warning}</dd>
                  </div>,
                  <div key={`${th.metric}-c`} className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdCritical').replace('{metric}', th.metric)}</dt>
                    <dd className="text-rose-400">{th.critical}</dd>
                  </div>,
                ])
              : (
                <>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdWarning').replace('{metric}', t('rtm.metric.cpu'))}</dt>
                    <dd className="text-amber-400">70%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdCritical').replace('{metric}', t('rtm.metric.cpu'))}</dt>
                    <dd className="text-rose-400">90%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdWarning').replace('{metric}', t('rtm.metric.memory'))}</dt>
                    <dd className="text-amber-400">80%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdCritical').replace('{metric}', t('rtm.metric.memory'))}</dt>
                    <dd className="text-rose-400">95%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdWarning').replace('{metric}', t('rtm.metric.disk'))}</dt>
                    <dd className="text-amber-400">85%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdCritical').replace('{metric}', t('rtm.metric.disk'))}</dt>
                    <dd className="text-rose-400">95%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdWarning').replace('{metric}', t('rtm.metric.network'))}</dt>
                    <dd className="text-amber-400">70%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">{t('rtm.thresholdCritical').replace('{metric}', t('rtm.metric.network'))}</dt>
                    <dd className="text-rose-400">90%</dd>
                  </div>
                </>
              )}
          </dl>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">{t('rtm.quickActions')}</h3>
          <div className="flex flex-col gap-2">
            <button
              type="button"
              onClick={() => selectedWorkspaceId && navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services`)}
              className="flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/80 hover:bg-white/10"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="23 4 23 10 17 10" />
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
              </svg>
              {t('rtm.serviceStatusList')}
            </button>
            <button
              type="button"
              onClick={() => selectedWorkspaceId && navigate(`/app/workspaces/${selectedWorkspaceId}/observe/targets`)}
              className="flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/80 hover:bg-white/10"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
              </svg>
              {t('rtm.hostsLink')}
            </button>
            <button
              type="button"
              onClick={() => selectedWorkspaceId && navigate(`/app/workspaces/${selectedWorkspaceId}/observe/alert-management`)}
              className="flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/80 hover:bg-white/10"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
              </svg>
              {t('rtm.alertConfiguration')}
            </button>
          </div>
        </div>
      </div>
      <AIAgentDrawer
        open={aiDrawerOpen}
        workspaceId={wsId}
        seed={aiSeed}
        onClose={() => setAiDrawerOpen(false)}
      />
    </div>
  )
}

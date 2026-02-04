import { useState, useEffect, useCallback } from 'react'
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
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveKpis } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { observeService } from '../../services/observeService'
import type { RealTimeMetrics, SystemInfo } from '../../types/observe'

const MAX_POINTS = 120

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
  temp: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z" />
    </svg>
  ),
}

export default function RealTimeMonitoring() {
  const navigate = useNavigate()
  const { selectedWorkspaceId } = useWorkspaceContext()

  const [isLive, setIsLive] = useState(true)
  const [refreshInterval, setRefreshInterval] = useState(5)
  const [refreshKey, setRefreshKey] = useState(0)
  const [metrics, setMetrics] = useState<RealTimeMetrics | null>(null)
  const [systemInfo, setSystemInfo] = useState<SystemInfo | null>(null)
  const [thresholds, setThresholds] = useState<Array<{ metric: string; warning: string; critical: string }>>([])
  const [timeSeries, setTimeSeries] = useState<Array<{ time: string; cpu: number; memory: number; disk: number; network: number }>>([])
  const [metricsError, setMetricsError] = useState<string | null>(null)

  const {
    hostTotals,
    serviceTotals,
    problems,
    engineUnreachable,
    stale,
    lastPollAt,
    loading: kpisLoading,
    error: kpisError,
  } = useObserveKpis(selectedWorkspaceId ?? null, refreshKey)

  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null

  // Poll Observe KPIs only when Live is on
  useEffect(() => {
    if (!isLive || !selectedWorkspaceId) return
    const t = setInterval(() => setRefreshKey((k) => k + 1), refreshInterval * 1000)
    return () => clearInterval(t)
  }, [isLive, selectedWorkspaceId, refreshInterval])

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

  // Poll system metrics only when Live is on
  useEffect(() => {
    if (!isLive || !wsId) return
    fetchMetrics()
    const t = setInterval(fetchMetrics, refreshInterval * 1000)
    return () => clearInterval(t)
  }, [isLive, wsId, refreshInterval, fetchMetrics])

  const totalHosts = hostTotals.up + hostTotals.down + hostTotals.unreachable + hostTotals.pending
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
  const tempVal = m?.temperature?.value ?? 0

  if (kpisLoading && !hostTotals.up && !serviceTotals.ok && totalServices === 0) {
    return (
      <div className="flex items-center justify-center py-24">
        <div className="text-sm text-white/60">Loading real-time data...</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Real-time Monitoring"
        subtitle="Live system metrics and performance indicators."
        actions={
          <div className="flex items-center gap-2">
            <select
              value={refreshInterval}
              onChange={(e) => setRefreshInterval(Number(e.target.value))}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
            >
              <option value={5} className="bg-slate-900 text-white">5 seconds</option>
              <option value={10} className="bg-slate-900 text-white">10 seconds</option>
              <option value={30} className="bg-slate-900 text-white">30 seconds</option>
              <option value={60} className="bg-slate-900 text-white">60 seconds</option>
              <option value={90} className="bg-slate-900 text-white">90 seconds</option>
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
              title={isLive ? 'Click to pause updates' : 'Click to resume live updates'}
            >
              <span className={`h-1.5 w-1.5 rounded-full ${isLive ? 'bg-rose-400 animate-pulse' : 'bg-white/50'}`} />
              {isLive ? 'Live' : 'Paused'}
            </button>
            <button
              type="button"
              onClick={() => {
                setRefreshKey((k) => k + 1)
                if (wsId) fetchMetrics()
              }}
              className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/80 hover:bg-white/10"
            >
              Refresh
            </button>
            <button
              title="Configure thresholds and targets in Monitored Targets"
              disabled
              className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-white/40"
              aria-label="Configure"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="inline-block">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
              </svg>
            </button>
          </div>
        }
      />

      {kpisError && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {kpisError}
        </div>
      )}

      {metricsError && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
          System metrics: {metricsError}. Showing last known values. Enable Live for server metrics (Linux).
        </div>
      )}

      {(engineUnreachable || stale) && (
        <div
          className={`rounded-lg border px-4 py-3 text-sm ${
            engineUnreachable
              ? 'border-rose-500/30 bg-rose-500/10 text-rose-100'
              : 'border-yellow-500/30 bg-yellow-500/10 text-yellow-100'
          }`}
        >
          {engineUnreachable
            ? 'Monitoring engine is unreachable. Status may be outdated.'
            : `Data may be stale. Last poll: ${lastPollAt ? new Date(lastPollAt).toLocaleString() : 'never'}.`}
        </div>
      )}

      {/* Top row: System metrics (reference-style) – live when Live is on */}
      <div className="grid gap-4 md:grid-cols-5">
        <MetricCard
          title="CPU Usage"
          value={m ? `${cpuVal}%` : '—'}
          detail={m?.cpu ? `${m.cpu.cores} • ${m.cpu.frequency}` : 'Enable Live for server metrics'}
          percentage={cpuVal}
          icon={Icons.cpu}
        />
        <MetricCard
          title="Memory"
          value={m ? `${memVal}%` : '—'}
          detail={m?.memory ? `${m.memory.used} / ${m.memory.total}` : '—'}
          percentage={memVal}
          icon={Icons.memory}
        />
        <MetricCard
          title="Disk I/O"
          value={m ? `${diskVal}%` : '—'}
          detail={m?.diskIO ? `${m.diskIO.type} • ${m.diskIO.throughput}` : '—'}
          percentage={diskVal}
          icon={Icons.disk}
        />
        <MetricCard
          title="Network"
          value={m ? `${netVal}%` : '—'}
          detail={m?.network ? `${m.network.speed} • ${m.network.type}` : '—'}
          percentage={netVal}
          icon={Icons.network}
        />
        <MetricCard
          title="Temperature"
          value={m && tempVal > 0 ? `${tempVal}°C` : '—'}
          detail={m?.temperature?.source ?? '—'}
          percentage={tempVal > 0 ? Math.min(100, Math.round((tempVal / 80) * 100)) : 0}
          icon={Icons.temp}
        />
      </div>

      {/* Charts – live time-series when Live is on */}
      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-3 flex items-center gap-2">
            <h3 className="text-sm font-semibold">System Performance</h3>
            {isLive && (
              <span className="rounded bg-rose-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-200">
                Live
              </span>
            )}
          </div>
          <p className="mb-3 text-xs text-white/50">Real-time CPU, Memory, and Disk usage.</p>
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
                {isLive ? 'Collecting samples…' : 'Enable Live to see history'}
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
          <p className="mb-3 text-xs text-white/50">Real-time network utilization.</p>
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
                {isLive ? 'Collecting samples…' : 'Enable Live to see history'}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Observe status + KPIs */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-2 text-sm font-semibold">Observe status</h3>
        <p className="text-xs text-white/60">
          Host and service totals: <strong>{hostTotals.up} up</strong>, <strong>{serviceTotals.ok} OK</strong>,{' '}
          <strong>{problems} problems</strong>, {serviceTotals.pending} pending. Last poll:{' '}
          {lastPollAt ? new Date(lastPollAt).toLocaleString() : '—'}.{' '}
          {selectedWorkspaceId && (
            <>
              <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`} className="text-sky-300 hover:underline">
                Monitored Targets
              </Link>
              {' · '}
              <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/services`} className="text-sky-300 hover:underline">
                Services
              </Link>
            </>
          )}
        </p>
      </div>

      {/* Bottom row: System Information, Performance Thresholds, Quick Actions */}
      <div className="grid gap-4 md:grid-cols-3">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">System Information</h3>
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">Hostname</dt>
              <dd className="font-mono text-right text-xs truncate" title={systemInfo?.hostname ?? '—'}>
                {systemInfo?.hostname ?? '—'}
              </dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">OS</dt>
              <dd className="font-mono text-right text-xs truncate" title={systemInfo?.os ?? '—'}>
                {systemInfo?.os ?? '—'}
              </dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">Kernel</dt>
              <dd className="font-mono text-right text-xs truncate" title={systemInfo?.kernel ?? '—'}>
                {systemInfo?.kernel ?? '—'}
              </dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">Uptime</dt>
              <dd className="font-mono text-right text-xs">{systemInfo?.uptime ?? '—'}</dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-white/60 shrink-0">Load Average</dt>
              <dd className="font-mono text-right text-xs">{systemInfo?.loadAverage ?? '—'}</dd>
            </div>
          </dl>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">Performance thresholds</h3>
          <p className="text-xs text-white/50 mb-3">Default dashboard thresholds. Per-service limits in Monitored Targets.</p>
          <dl className="space-y-1.5 text-xs">
            {thresholds.length > 0
              ? thresholds.flatMap((t) => [
                  <div key={`${t.metric}-w`} className="flex justify-between">
                    <dt className="text-white/60">{t.metric} Warning</dt>
                    <dd className="text-amber-400">{t.warning}</dd>
                  </div>,
                  <div key={`${t.metric}-c`} className="flex justify-between">
                    <dt className="text-white/60">{t.metric} Critical</dt>
                    <dd className="text-rose-400">{t.critical}</dd>
                  </div>,
                ])
              : (
                <>
                  <div className="flex justify-between">
                    <dt className="text-white/60">CPU Warning</dt>
                    <dd className="text-amber-400">70%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">CPU Critical</dt>
                    <dd className="text-rose-400">90%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">Memory Warning</dt>
                    <dd className="text-amber-400">80%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">Memory Critical</dt>
                    <dd className="text-rose-400">95%</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">Temp Warning</dt>
                    <dd className="text-amber-400">65°C</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-white/60">Temp Critical</dt>
                    <dd className="text-rose-400">80°C</dd>
                  </div>
                </>
              )}
          </dl>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">Quick actions</h3>
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
              Service status list
            </button>
            <button
              type="button"
              onClick={() => selectedWorkspaceId && navigate(`/app/workspaces/${selectedWorkspaceId}/observe/targets`)}
              className="flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/80 hover:bg-white/10"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
              </svg>
              Monitored Targets
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
              Alert configuration
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

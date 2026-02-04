import { useState, useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveKpis } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'

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
          {detail && <p className="mt-1 text-xs text-white/60 truncate" title={detail}>{detail}</p>}
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

export default function RealTimeMonitoring() {
  const navigate = useNavigate()
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [refreshKey, setRefreshKey] = useState(0)
  const {
    hostTotals,
    serviceTotals,
    problems,
    engineUnreachable,
    stale,
    lastPollAt,
    loading,
    error,
  } = useObserveKpis(selectedWorkspaceId, refreshKey)

  const [refreshInterval, setRefreshInterval] = useState(30)
  const isLive = refreshInterval <= 10

  useEffect(() => {
    if (!selectedWorkspaceId) return
    const t = setInterval(() => setRefreshKey((k) => k + 1), refreshInterval * 1000)
    return () => clearInterval(t)
  }, [selectedWorkspaceId, refreshInterval])

  const totalHosts = hostTotals.up + hostTotals.down + hostTotals.unreachable + hostTotals.pending
  const totalServices =
    serviceTotals.ok +
    serviceTotals.warning +
    serviceTotals.critical +
    serviceTotals.unknown +
    serviceTotals.pending +
    (serviceTotals.unreachable ?? 0)
  const hostsUpPct = totalHosts > 0 ? Math.round((hostTotals.up / totalHosts) * 100) : 0
  const servicesOkPct = totalServices > 0 ? Math.round((serviceTotals.ok / totalServices) * 100) : 0
  const problemsPct = totalServices > 0 ? Math.min(100, Math.round((problems / totalServices) * 100)) : 0

  if (loading && !hostTotals.up && !serviceTotals.ok && totalServices === 0) {
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
            <span
              className={`inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase ${
                isLive ? 'bg-rose-500/20 text-rose-200 border border-rose-500/30' : 'bg-white/10 text-white/70 border border-white/10'
              }`}
            >
              Live
            </span>
            <button
              onClick={() => setRefreshKey((k) => k + 1)}
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

      {error && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {error}
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

      {/* Top metric cards – real data from ShieldObserve */}
      <div className="grid gap-4 md:grid-cols-5">
        <MetricCard
          title="Hosts up"
          value={hostTotals.up}
          detail={`Down: ${hostTotals.down} · Unreachable: ${hostTotals.unreachable} · Pending: ${hostTotals.pending}`}
          percentage={totalHosts > 0 ? hostsUpPct : 0}
          icon={
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="2" width="20" height="8" rx="2" />
              <rect x="2" y="14" width="20" height="8" rx="2" />
            </svg>
          }
        />
        <MetricCard
          title="Services OK"
          value={serviceTotals.ok}
          detail={`Warning: ${serviceTotals.warning} · Critical: ${serviceTotals.critical} · Unknown: ${serviceTotals.unknown}`}
          percentage={servicesOkPct}
          icon={
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
              <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
          }
        />
        <MetricCard
          title="Problems"
          value={problems}
          detail="Warning + Critical + Unknown + Unreachable"
          percentage={problemsPct}
          icon={
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          }
        />
        <MetricCard
          title="Pending"
          value={serviceTotals.pending}
          detail="Awaiting first check"
          icon={
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <polyline points="12 6 12 12 16 14" />
            </svg>
          }
        />
        <MetricCard
          title="Last poll"
          value={lastPollAt ? new Date(lastPollAt).toLocaleTimeString() : '—'}
          detail={lastPollAt ? new Date(lastPollAt).toLocaleDateString() : 'No data yet'}
          icon={
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <polyline points="12 6 12 12 16 14" />
            </svg>
          }
        />
      </div>

      {/* Observe status – real data summary */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-2 text-sm font-semibold">Observe status</h3>
        <p className="text-xs text-white/60">
          Host and service totals are driven by <strong>ShieldObserve</strong>. Use{' '}
          {selectedWorkspaceId ? (
            <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`} className="text-sky-300 hover:underline">
              Monitored Targets
            </Link>
          ) : (
            'Monitored Targets'
          )}{' '}
          to add hosts and services, then{' '}
          {selectedWorkspaceId ? (
            <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/services`} className="text-sky-300 hover:underline">
              Services
            </Link>
          ) : (
            'Services'
          )}{' '}
          for the full list and filters.
        </p>
        <p className="mt-3 text-xs text-white/40">
          Per-host CPU, Memory, Disk I/O, and Network metrics require an optional metrics agent (future enhancement).
        </p>
      </div>

      {/* Bottom row: System summary, Thresholds, Quick actions */}
      <div className="grid gap-4 md:grid-cols-3">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">Workspace summary</h3>
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between">
              <dt className="text-white/60">Hosts monitored</dt>
              <dd className="font-mono">{totalHosts}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-white/60">Total services</dt>
              <dd className="font-mono">{totalServices}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-white/60">Last poll</dt>
              <dd className="font-mono text-xs">{lastPollAt ? new Date(lastPollAt).toLocaleString() : '—'}</dd>
            </div>
          </dl>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-3 text-xs font-semibold text-white/70 uppercase tracking-wider">Performance thresholds</h3>
          <p className="text-xs text-white/50 mb-3">Per-service thresholds are set in Monitored Targets → service Configuration.</p>
          <dl className="space-y-1.5 text-xs">
            <div className="flex justify-between">
              <dt className="text-white/60">Service check interval</dt>
              <dd>Configurable per service (min)</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-white/60">Warning / Critical</dt>
              <dd>Defined per plugin (e.g. disk %, ping RTA)</dd>
            </div>
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

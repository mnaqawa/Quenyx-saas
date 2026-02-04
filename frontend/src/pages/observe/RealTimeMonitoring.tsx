import { useState, useEffect } from 'react'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveKpis } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'

export default function RealTimeMonitoring() {
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

  const [refreshInterval, setRefreshInterval] = useState(90)
  useEffect(() => {
    if (!selectedWorkspaceId) return
    const t = setInterval(() => setRefreshKey((k) => k + 1), refreshInterval * 1000)
    return () => clearInterval(t)
  }, [selectedWorkspaceId, refreshInterval])

  if (loading && !hostTotals.up && !serviceTotals.ok && serviceTotals.pending === 0) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Real-time Monitoring"
        subtitle="Observe host and service status for this workspace"
        actions={
          <>
            <select
              value={refreshInterval}
              onChange={(e) => setRefreshInterval(Number(e.target.value))}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
            >
              <option value={30} className="bg-slate-900 text-white">30 seconds</option>
              <option value={60} className="bg-slate-900 text-white">60 seconds</option>
              <option value={90} className="bg-slate-900 text-white">90 seconds</option>
            </select>
            <button
              onClick={() => setRefreshKey((k) => k + 1)}
              className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70 hover:bg-white/10"
            >
              Refresh
            </button>
            <button
              title="Coming soon"
              disabled
              className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40"
            >
              Configure
            </button>
          </>
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

      <div className="grid gap-4 md:grid-cols-5">
        <StatCard
          title="Hosts up"
          value={String(hostTotals.up)}
          detail={`Down: ${hostTotals.down} · Unreachable: ${hostTotals.unreachable} · Pending: ${hostTotals.pending}`}
        />
        <StatCard
          title="Services OK"
          value={String(serviceTotals.ok)}
          detail={`Warning: ${serviceTotals.warning} · Critical: ${serviceTotals.critical}`}
        />
        <StatCard
          title="Problems"
          value={String(problems)}
          detail="Warning + Critical + Unknown + Unreachable"
          percentage={problems > 0 ? 100 : 0}
        />
        <StatCard
          title="Pending"
          value={String(serviceTotals.pending)}
          detail="Awaiting first check"
        />
        <StatCard
          title="Last poll"
          value={lastPollAt ? new Date(lastPollAt).toLocaleTimeString() : '—'}
          detail={lastPollAt ? new Date(lastPollAt).toLocaleDateString() : 'No data yet'}
        />
      </div>

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">Observe status</h3>
        <p className="text-xs text-white/60">
          Host and service totals are driven by ShieldObserve. Use <strong>Monitored Targets</strong> to
          add hosts and services, then <strong>Services</strong> for the full list and filters.
        </p>
      </div>
    </div>
  )
}

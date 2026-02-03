import { useState, useMemo, useEffect, Fragment } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveServices } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'

const statusOptions = ['ok', 'warning', 'critical', 'unknown', 'pending'] as const
const limitOptions = [25, 50, 100, 200]

function formatDuration(seconds: number): string {
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60

  const parts: string[] = []
  if (days > 0) parts.push(`${days}d`)
  if (hours > 0) parts.push(`${hours}h`)
  if (minutes > 0) parts.push(`${minutes}m`)
  if (secs > 0 && parts.length < 3) parts.push(`${secs}s`)

  return parts.join(' ') || '0s'
}

function formatDateTime(dateString: string | null | undefined): string {
  if (dateString == null || String(dateString).trim() === '') return '—'
  const date = new Date(dateString)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString('en-US', {
    month: '2-digit',
    day: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  })
}

export default function Services() {
  const { selectedWorkspaceId, modulesWithAccess, allowedByKey } = useWorkspaceContext()
  const [searchParams, setSearchParams] = useSearchParams()
  const navigate = useNavigate()
  
  // Initialize state from URL query params
  const [searchQuery, setSearchQuery] = useState(searchParams.get('q') || '')
  const [selectedStatuses, setSelectedStatuses] = useState<string[]>(() => {
    const statusParam = searchParams.get('status')
    return statusParam ? statusParam.split(',') : []
  })
  const [limit, setLimit] = useState(() => {
    const limitParam = searchParams.get('limit')
    return limitParam ? Number(limitParam) : 100
  })
  const [problemsOnly, setProblemsOnly] = useState(() => {
    return searchParams.get('problems') === '1'
  })
  const [refreshInterval, setRefreshInterval] = useState(() => {
    const intervalParam = searchParams.get('interval')
    return intervalParam ? `${intervalParam} seconds` : '90 seconds'
  })
  const [expandedRowKey, setExpandedRowKey] = useState<string | null>(null)

  // Sync state changes to URL query params
  useEffect(() => {
    const params = new URLSearchParams()
    if (searchQuery) params.set('q', searchQuery)
    if (selectedStatuses.length > 0) params.set('status', selectedStatuses.join(','))
    if (limit !== 100) params.set('limit', limit.toString())
    if (problemsOnly) params.set('problems', '1')
    const intervalSeconds = refreshInterval.replace(' seconds', '')
    if (intervalSeconds !== '90') params.set('interval', intervalSeconds)
    
    // Only update URL if params changed (avoid infinite loop)
    const currentParams = searchParams.toString()
    const newParams = params.toString()
    if (currentParams !== newParams) {
      setSearchParams(params, { replace: true })
    }
  }, [searchQuery, selectedStatuses, limit, problemsOnly, refreshInterval, setSearchParams, searchParams])

  const isLocked = useMemo(() => {
    const observeModule = modulesWithAccess?.find((m) => m.key === 'shieldobserve')
    return observeModule ? !allowedByKey['shieldobserve'] : false
  }, [modulesWithAccess, allowedByKey])

  // Auto-refresh based on interval - trigger re-fetch by updating a dependency
  const [refreshKey, setRefreshKey] = useState(0)
  
  const { data, loading, error } = useObserveServices({
    workspaceId: selectedWorkspaceId,
    q: searchQuery,
    statuses: selectedStatuses.length > 0 ? selectedStatuses : undefined,
    limit,
    problemsOnly,
    refreshKey, // Include refreshKey to trigger re-fetch on interval
  })
  useEffect(() => {
    if (!selectedWorkspaceId || isLocked) return
    
    const intervalSeconds = parseInt(refreshInterval.replace(' seconds', ''), 10)
    if (isNaN(intervalSeconds) || intervalSeconds < 30) return
    
    const interval = setInterval(() => {
      // Trigger refresh by updating key (this will cause useObserveServices to re-fetch)
      setRefreshKey((prev) => prev + 1)
    }, intervalSeconds * 1000)
    
    return () => clearInterval(interval)
  }, [selectedWorkspaceId, refreshInterval, isLocked])

  const toggleStatus = (status: string) => {
    setSelectedStatuses((prev) =>
      prev.includes(status) ? prev.filter((s) => s !== status) : [...prev, status]
    )
  }

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'ok':
        return 'bg-emerald-500/20 text-emerald-200 border-emerald-500/30'
      case 'warning':
        return 'bg-yellow-500/20 text-yellow-200 border-yellow-500/30'
      case 'critical':
        return 'bg-rose-500/20 text-rose-200 border-rose-500/30'
      case 'unknown':
        return 'bg-purple-500/20 text-purple-200 border-purple-500/30'
      case 'pending':
        return 'bg-sky-500/20 text-sky-200 border-sky-500/30'
      default:
        return 'bg-gray-500/20 text-gray-200 border-gray-500/30'
    }
  }

  const getRowBgColor = (status: string) => {
    switch (status) {
      case 'ok':
        return 'bg-white/0 hover:bg-white/5'
      case 'warning':
        return 'bg-yellow-500/5 hover:bg-yellow-500/10'
      case 'critical':
        return 'bg-rose-500/10 hover:bg-rose-500/15'
      case 'unknown':
        return 'bg-purple-500/5 hover:bg-purple-500/10'
      case 'pending':
        return 'bg-sky-500/5 hover:bg-sky-500/10'
      default:
        return 'bg-white/0 hover:bg-white/5'
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-sm text-white/60">Loading services...</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        Error loading services: {error}
      </div>
    )
  }

  if (!data) {
    return (
      <div className="rounded-lg border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/60">
        No data available
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Locked module banner - consistent with ObserveLayout */}
      {isLocked && (
        <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-2 text-sm text-yellow-200">
          <div className="flex items-center gap-2">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <span>ShieldObserve is locked. Some features are disabled.</span>
          </div>
        </div>
      )}

      {(data.engine_unreachable || data.stale) && (
        <div className={`rounded-lg border px-4 py-3 text-sm ${
          data.engine_unreachable
            ? 'border-rose-500/30 bg-rose-500/10 text-rose-100'
            : 'border-yellow-500/30 bg-yellow-500/10 text-yellow-100'
        }`}>
          {data.engine_unreachable ? (
            <span>Monitoring engine is unreachable. Status may be outdated.</span>
          ) : (
            <span>Data may be stale (last poll: {data.last_poll_at ? new Date(data.last_poll_at).toLocaleString() : 'never'}).</span>
          )}
        </div>
      )}

      <PageHeader
        title="Services"
        subtitle="All monitored services across the workspace"
        actions={
          <>
            <select
              value={refreshInterval}
              onChange={(e) => setRefreshInterval(e.target.value)}
              disabled={isLocked}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <option value="30 seconds" className="bg-slate-900 text-white">30 seconds</option>
              <option value="60 seconds" className="bg-slate-900 text-white">60 seconds</option>
              <option value="90 seconds" className="bg-slate-900 text-white">90 seconds</option>
            </select>
            <button
              onClick={() => {
                // Force refresh by updating refreshKey
                setRefreshKey((prev) => prev + 1)
              }}
              disabled={isLocked || loading}
              className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white/10"
            >
              {loading ? 'Refreshing...' : 'Refresh'}
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

      {/* Summary Totals */}
      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-lg border border-white/10 bg-white/5 p-4">
          <h3 className="mb-3 text-xs font-semibold text-white/70">Host Status Totals</h3>
          <div className="grid grid-cols-4 gap-2">
            <div>
              <div className="mb-1 text-xs text-white/60">Up</div>
              <div className="rounded bg-emerald-500/20 px-2 py-1 text-center text-sm font-semibold text-emerald-200">
                {data.hostTotals.up}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Down</div>
              <div className="rounded bg-gray-500/20 px-2 py-1 text-center text-sm font-semibold text-gray-200">
                {data.hostTotals.down}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Unreachable</div>
              <div className="rounded bg-gray-500/20 px-2 py-1 text-center text-sm font-semibold text-gray-200">
                {data.hostTotals.unreachable}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Pending</div>
              <div className="rounded bg-gray-500/20 px-2 py-1 text-center text-sm font-semibold text-gray-200">
                {data.hostTotals.pending}
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/5 p-4">
          <h3 className="mb-3 text-xs font-semibold text-white/70">Service Status Totals</h3>
          <div className="grid grid-cols-6 gap-2">
            <div>
              <div className="mb-1 text-xs text-white/60">OK</div>
              <div className="rounded bg-emerald-500/20 px-2 py-1 text-center text-sm font-semibold text-emerald-200">
                {data.serviceTotals.ok}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Warning</div>
              <div className="rounded bg-yellow-500/20 px-2 py-1 text-center text-sm font-semibold text-yellow-200">
                {data.serviceTotals.warning}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Unknown</div>
              <div className="rounded bg-purple-500/20 px-2 py-1 text-center text-sm font-semibold text-purple-200">
                {data.serviceTotals.unknown}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Critical</div>
              <div className="rounded bg-rose-500/20 px-2 py-1 text-center text-sm font-semibold text-rose-200">
                {data.serviceTotals.critical}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Pending</div>
              <div className="rounded bg-sky-500/20 px-2 py-1 text-center text-sm font-semibold text-sky-200">
                {data.serviceTotals.pending}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">Unreachable</div>
              <div className="rounded bg-rose-500/20 px-2 py-1 text-center text-sm font-semibold text-rose-200">
                {data.serviceTotals.unreachable ?? 0}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Filters Row */}
      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-4">
        <input
          type="text"
          placeholder="Search hosts, services, or info..."
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          disabled={isLocked}
          className="flex-1 min-w-[200px] rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50 disabled:cursor-not-allowed"
        />

        <div className="flex items-center gap-2">
          <span className="text-xs text-white/60">Status:</span>
          {statusOptions.map((status) => (
            <button
              key={status}
              onClick={() => !isLocked && toggleStatus(status)}
              disabled={isLocked}
              className={`rounded-full border px-2 py-1 text-[10px] font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${
                selectedStatuses.includes(status)
                  ? 'border-sky-500 bg-sky-500/20 text-sky-200'
                  : 'border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
              }`}
            >
              {status.charAt(0).toUpperCase() + status.slice(1)}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs text-white/60">Limit:</span>
          <select
            value={limit}
            onChange={(e) => setLimit(Number(e.target.value))}
            disabled={isLocked}
            className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs text-white disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {limitOptions.map((opt) => (
              <option key={opt} value={opt} className="bg-slate-900 text-white">
                {opt}
              </option>
            ))}
          </select>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => !isLocked && setProblemsOnly(false)}
            disabled={isLocked}
            className={`rounded px-2 py-1 text-[10px] font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${
              !problemsOnly
                ? 'bg-sky-500/20 text-sky-200 border border-sky-500/30'
                : 'border border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
            }`}
          >
            All Types ({data.items.length})
          </button>
          <button
            onClick={() => !isLocked && setProblemsOnly(true)}
            disabled={isLocked}
            className={`rounded px-2 py-1 text-[10px] font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${
              problemsOnly
                ? 'bg-sky-500/20 text-sky-200 border border-sky-500/30'
                : 'border border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
            }`}
          >
            All Problems ({data.serviceTotals.warning + data.serviceTotals.critical + data.serviceTotals.unknown})
          </button>
        </div>
      </div>

      {/* Main Table */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">Service Status Details For All Hosts</h3>
        <div className="overflow-x-auto">
          <table className="w-full border-collapse">
            <thead className="sticky top-0 bg-[#0f151d]">
              <tr className="border-b border-white/10">
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70 w-32">Host</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">Service</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70 w-28">Status</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">Last Check</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">Duration</th>
                <th className="px-3 py-2 text-center text-xs font-semibold text-white/70 w-20">Attempt</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">Status Information</th>
                <th className="px-3 py-2 text-right text-xs font-semibold text-white/70 w-24">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.items.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-3 py-8 text-center text-sm text-white/60">
                    No services found
                  </td>
                </tr>
              ) : (
                data.items.map((item, index) => {
                  const rowKey = `${item.host}-${item.service}-${index}`
                  const isExpanded = expandedRowKey === rowKey
                  const hasPerf = !!(item.perfData || item.longPluginOutput)
                  return (
                    <Fragment key={rowKey}>
                      <tr
                        className={`border-b border-white/5 ${getRowBgColor(item.status)}`}
                      >
                        <td className="px-3 py-2.5 text-[13px]">
                          <button
                            onClick={() => {
                              if (selectedWorkspaceId) {
                                navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(item.host)}`)
                              }
                            }}
                            className="hover:text-sky-200 transition"
                          >
                            {item.host}
                          </button>
                        </td>
                        <td className="px-3 py-2.5 text-[13px]">{item.service}</td>
                        <td className="px-3 py-2.5">
                          <span
                            className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-medium ${getStatusColor(item.status)}`}
                          >
                            {item.status.toUpperCase()}
                          </span>
                        </td>
                        <td className="px-3 py-2.5 text-[13px] text-white/70 font-mono tabular-nums">{formatDateTime(item.lastCheckAt)}</td>
                        <td className="px-3 py-2.5 text-[13px] text-white/70">{formatDuration(item.durationSec)}</td>
                        <td className="px-3 py-2.5 text-center text-[13px] text-white/70 font-mono">{item.attempt}</td>
                        <td className="px-3 py-2.5 text-[13px] text-white/70">
                          <div className="line-clamp-2" title={item.info}>
                            {item.info || '—'}
                          </div>
                        </td>
                        <td className="px-3 py-2.5">
                          <div className="flex items-center justify-end gap-2">
                            {hasPerf && (
                              <button
                                type="button"
                                onClick={() => setExpandedRowKey(isExpanded ? null : rowKey)}
                                title={isExpanded ? 'Hide perf data' : 'Show perf data'}
                                className="rounded border border-white/10 bg-white/5 p-1.5 text-white/70 hover:bg-white/10"
                              >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className={isExpanded ? 'rotate-180' : ''}>
                                  <path d="M6 9l6 6 6-6" />
                                </svg>
                              </button>
                            )}
                            <button
                              disabled
                              title="Acknowledge (Coming soon)"
                              className="rounded border border-white/10 bg-white/5 p-1.5 text-white/30 opacity-50 cursor-not-allowed"
                            >
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M20 6L9 17l-5-5" />
                              </svg>
                            </button>
                            <button
                              disabled
                              title="Schedule Downtime (Coming soon)"
                              className="rounded border border-white/10 bg-white/5 p-1.5 text-white/30 opacity-50 cursor-not-allowed"
                            >
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                              </svg>
                            </button>
                          </div>
                        </td>
                      </tr>
                      {isExpanded && hasPerf && (
                        <tr key={`${rowKey}-exp`} className="border-b border-white/5 bg-white/[0.02]">
                          <td colSpan={8} className="px-3 py-2 text-xs text-white/60">
                            <div className="space-y-1">
                              {item.perfData && (
                                <div>
                                  <span className="font-medium text-white/70">Perf data:</span>{' '}
                                  <span className="font-mono">{item.perfData}</span>
                                </div>
                              )}
                              {item.longPluginOutput && (
                                <div>
                                  <span className="font-medium text-white/70">Long output:</span>{' '}
                                  <span className="whitespace-pre-wrap">{item.longPluginOutput}</span>
                                </div>
                              )}
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

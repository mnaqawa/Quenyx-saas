import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { dashboardService, DashboardData, Module, PerformanceSeries, AlertsByModule } from '../services/dashboardService'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { useObserveServices } from '../hooks/useObserveData'

const MODULE_CAPACITY = 10

const getStatusLabel = (health: string): string => {
  return health === 'ok' ? 'Operational' : 'Degraded'
}

const getHealthLabel = (activeRatio: number): string => {
  if (activeRatio >= 0.75) return 'Excellent'
  if (activeRatio >= 0.5) return 'Good'
  if (activeRatio >= 0.25) return 'Fair'
  return 'Critical'
}

const toPercent = (value: number): string => `${Math.round(value * 10) / 10}%`

const buildAlerts = (modules: Module[]) => {
  if (modules.length === 0) return []

  return modules.slice(0, 5).map((module) => {
    const primary =
      module.status === 'active' ? 7 : module.status === 'maintenance' ? 5 : 3
    const secondary =
      module.subscription_state === 'active'
        ? 2
        : module.subscription_state === 'trial'
        ? 1
        : module.subscription_state === 'expired'
        ? 3
        : 2
    return {
      label: module.name,
      primary,
      secondary,
    }
  })
}

const getSeriesColor = (label: string): string => {
  if (label === 'CPU') return '#38bdf8'
  if (label === 'Memory') return '#22d3ee'
  return '#5eead4'
}

const buildPerformanceSeries = (modules: Module[]): PerformanceSeries[] => {
  const activeRatio = modules.length ? modules.filter((module) => module.status === 'active').length / modules.length : 0.55
  const base = activeRatio * 100 || 55
  const series = [
    { label: 'CPU', color: '#38bdf8', values: [base - 15, base - 4, base + 10, base + 16, base + 6, base - 8] },
    { label: 'Memory', color: '#22d3ee', values: [base - 22, base - 10, base + 2, base + 9, base + 1, base - 12] },
    { label: 'Network', color: '#5eead4', values: [base - 30, base - 18, base - 6, base + 2, base - 3, base - 20] },
  ]
  return series.map((entry) => ({
    label: entry.label,
    values: entry.values.map((value) => Math.max(10, Math.min(90, Math.round(value)))),
  }))
}

const buildWeeklyUptime = (modules: Module[]): number[] => {
  if (modules.length === 0) return [0, 0, 0, 0, 0, 0, 0]
  const activeRatio = modules.filter((module) => module.status === 'active').length / modules.length
  const base = 98.8 + activeRatio * 1.1
  return [base - 0.4, base - 0.6, base - 0.3, base + 0.1, base - 0.7, base - 0.2, base].map((value) =>
    Math.round(value * 10) / 10
  )
}

function Dashboard() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const { workspaces, workspacesError, isLoadingWorkspaces, selectedWorkspaceId, modulesWithAccess, allowedByKey } = useWorkspaceContext()
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  
  // Check if ShieldObserve is available and unlocked
  const observeModule = modulesWithAccess?.find((m) => m.key === 'shieldobserve')
  const hasObserveAccess = observeModule ? allowedByKey['shieldobserve'] : false
  
  // Fetch Observe summary data if workspace is selected and module is accessible
  const { data: observeData, loading: observeLoading } = useObserveServices({
    workspaceId: hasObserveAccess && selectedWorkspaceId ? selectedWorkspaceId : null,
    limit: 10, // Get top 10 problems for dashboard
    problemsOnly: true, // Only show problems
  })

  useEffect(() => {
    // Don't fetch dashboard if workspaces are still loading or if there's a workspace error
    if (isLoadingWorkspaces) {
      return
    }

    // If there's a workspace error, show that instead of trying to load dashboard
    if (workspacesError) {
      setError(workspacesError)
      setLoading(false)
      return
    }

    // If no workspaces available, show helpful message
    if (workspaces.length === 0) {
      setError('No workspaces available. Please create a workspace to get started.')
      setLoading(false)
      return
    }

    const fetchDashboard = async () => {
      try {
        const dashboardData = await dashboardService.getDashboard()
        setData(dashboardData)
        setError(null)
      } catch (err) {
        // Improved error handling with more specific messages
        let errorMessage = 'Failed to load dashboard'
        if (err instanceof Error) {
          if (err.message.includes('401') || err.message.includes('Unauthorized')) {
            errorMessage = 'Authentication required'
          } else if (err.message.includes('403') || err.message.includes('Forbidden')) {
            errorMessage = 'Access denied'
          } else if (err.message.includes('404') || err.message.includes('Not Found')) {
            errorMessage = 'Dashboard data not found'
          } else if (err.message.includes('Network') || err.message.includes('fetch')) {
            errorMessage = 'Network error - please check your connection'
          } else if (err.message && err.message !== 'An error occurred' && err.message !== 'An unexpected error occurred') {
            errorMessage = err.message
          }
        }
        setError(errorMessage)
      } finally {
        setLoading(false)
      }
    }

    fetchDashboard()
  }, [isLoadingWorkspaces, workspacesError, workspaces.length])

  const metrics = useMemo(() => {
    const modules = data?.modules ?? []
    const total = modules.length
    const activeCount = modules.filter((module) => module.status === 'active').length
    const maintenanceCount = modules.filter((module) => module.status === 'maintenance').length
    const inactiveCount = modules.filter((module) => module.status === 'inactive').length
    const activeRatio = total > 0 ? activeCount / total : 0
    const dataSync = total > 0 ? activeRatio * 100 : 0

    return {
      modules,
      total,
      activeCount,
      maintenanceCount,
      inactiveCount,
      activeRatio,
      dataSync,
      statusLabel: getStatusLabel(data?.platform_health ?? 'ok'),
      healthLabel: getHealthLabel(activeRatio),
    }
  }, [data])

  const performanceSeries = useMemo(() => {
    if (data?.performance_series?.length) {
      return data.performance_series
    }
    return buildPerformanceSeries(metrics.modules)
  }, [data?.performance_series, metrics.modules])

  const weeklyUptime = useMemo(() => {
    if (data?.weekly_uptime?.length) {
      return data.weekly_uptime
    }
    return buildWeeklyUptime(metrics.modules)
  }, [data?.weekly_uptime, metrics.modules])

  const alertsByModule: AlertsByModule[] = useMemo(() => {
    if (data?.alerts_by_module?.length) {
      return data.alerts_by_module
    }
    return buildAlerts(metrics.modules)
  }, [data?.alerts_by_module, metrics.modules])

  const toPoints = (values: number[]) => {
    if (values.length === 1) {
      return '0,100'
    }
    return values
      .map((value, index) => {
        const x = (index / (values.length - 1)) * 260
        const y = 100 - value
        return `${x},${y}`
      })
      .join(' ')
  }

  const normalize = (values: number[]) => {
    const min = Math.min(...values)
    const max = Math.max(...values)
    if (min === max) {
      return values.map(() => 50)
    }
    return values.map((value) => ((value - min) / (max - min)) * 100)
  }

  if (loading || isLoadingWorkspaces) {
    return <div className="text-sm text-white/60">{t('common.loadingDashboard')}</div>
  }

  if (error) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {error}
        </div>
        {workspaces.length === 0 && !workspacesError && (
          <div className="rounded-lg border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
            <p className="font-semibold">Get Started</p>
            <p className="mt-1 text-xs text-sky-200/80">
              Create your first workspace to start using PortShield SaaS.
            </p>
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight text-white">
            {t('dashboard.title')}
          </h1>
          <p className="text-sm text-white/60">{t('dashboard.subtitle')}</p>
        </div>
        <button
          type="button"
          className="inline-flex items-center justify-center rounded-full border border-white/15 px-4 py-2 text-xs font-semibold text-white/80 transition hover:border-white/30 hover:text-white"
        >
          {t('common.customizeDashboard')}
        </button>
      </div>

      {hasObserveAccess && selectedWorkspaceId && (
        <div className="rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 flex flex-wrap items-center gap-2 text-sm">
          <span className="text-white/60">This workspace:</span>
          {observeLoading ? (
            <span className="text-white/50">Loading…</span>
          ) : observeData ? (
            <>
              <span className="font-medium text-emerald-400">{observeData.hostTotals?.up ?? 0} hosts up</span>
              <span className="text-white/40">·</span>
              <span className="font-medium text-emerald-400">{observeData.serviceTotals?.ok ?? 0} services OK</span>
              <span className="text-white/40">·</span>
              <span className="font-medium text-rose-400">{(observeData.serviceTotals?.warning ?? 0) + (observeData.serviceTotals?.critical ?? 0)} problems</span>
              <Link
                to={`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`}
                className="ml-2 text-sky-300 hover:text-sky-200 text-xs font-medium"
              >
                View Observe →
              </Link>
            </>
          ) : (
            <span className="text-white/50">No data yet</span>
          )}
        </div>
      )}

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
        <div className="flex items-center justify-between gap-4">
          <div>
            <div className="text-sm font-semibold text-white">{t('dashboard.summaryTitle')}</div>
            <p className="mt-1 text-xs text-white/60">
              {t('dashboard.summaryDesc')}
            </p>
          </div>
          <span className="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-emerald-200">
            {metrics.statusLabel}
          </span>
        </div>
        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {[
            { label: t('dashboard.statusLabel'), value: metrics.statusLabel },
            { label: t('dashboard.connectedModules'), value: `${metrics.total}/${MODULE_CAPACITY}` },
            { label: t('dashboard.realtimeUpdates'), value: metrics.activeRatio >= 0.5 ? 'Active' : 'Monitoring' },
            { label: t('dashboard.widgets'), value: `${metrics.total * 3} Active` },
            { label: t('dashboard.sync'), value: toPercent(metrics.dataSync) },
            { label: t('dashboard.health'), value: metrics.healthLabel },
          ].map((metric) => (
            <div key={metric.label} className="rounded-xl border border-white/10 bg-[#0b1118] p-4">
              <p className="text-xs text-white/60">{metric.label}</p>
              <p className="mt-2 text-sm font-semibold text-white">{metric.value}</p>
            </div>
          ))}
        </div>
      </section>

      <div className="grid gap-4 lg:grid-cols-2">
        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="space-y-1">
            <h2 className="text-sm font-semibold text-white">{t('dashboard.systemPerformance')}</h2>
            <p className="text-xs text-white/60">{t('dashboard.performanceDesc')}</p>
          </div>
          <div className="mt-4">
            <svg viewBox="0 0 260 110" className="h-40 w-full">
              <defs>
                <linearGradient id="grid" x1="0" x2="0" y1="0" y2="1">
                  <stop offset="0%" stopColor="rgba(255,255,255,0.12)" />
                  <stop offset="100%" stopColor="rgba(255,255,255,0)" />
                </linearGradient>
              </defs>
              <rect x="0" y="0" width="260" height="100" fill="url(#grid)" opacity="0.08" />
              {performanceSeries.map((series) => (
                <polyline
                  key={series.label}
                  fill="none"
                  stroke={getSeriesColor(series.label)}
                  strokeWidth="2"
                  points={toPoints(series.values)}
                  opacity="0.9"
                />
              ))}
            </svg>
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-white/70">
              {performanceSeries.map((series) => (
                <span key={series.label} className="flex items-center gap-2">
                  <span
                    className="h-2 w-2 rounded-full"
                    style={{ backgroundColor: getSeriesColor(series.label) }}
                  />
                  {series.label}
                </span>
              ))}
            </div>
          </div>
        </section>

        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="space-y-1">
            <h2 className="text-sm font-semibold text-white">{t('dashboard.moduleStatus')}</h2>
            <p className="text-xs text-white/60">{t('dashboard.moduleStatusDesc')}</p>
          </div>
          <div className="mt-6 flex items-center gap-6">
            <div
              className="h-28 w-28 rounded-full"
              style={{
                background: (() => {
                  const total = metrics.total || 1
                  const operational = (metrics.activeCount / total) * 360
                  const maintenance = (metrics.maintenanceCount / total) * 360
                  const warning = (metrics.inactiveCount / total) * 360
                  return `conic-gradient(#22c55e 0deg ${operational}deg, #0ea5e9 ${operational}deg ${
                    operational + maintenance
                  }deg, #f97316 ${operational + maintenance}deg ${operational + maintenance + warning}deg)`
                })(),
              }}
            />
            <div className="space-y-2 text-xs text-white/70">
              <div className="flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-emerald-500" />
                Operational {toPercent(metrics.total ? (metrics.activeCount / metrics.total) * 100 : 0)}
              </div>
              <div className="flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-sky-500" />
                Maintenance {toPercent(metrics.total ? (metrics.maintenanceCount / metrics.total) * 100 : 0)}
              </div>
              <div className="flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-orange-500" />
                Warning {toPercent(metrics.total ? (metrics.inactiveCount / metrics.total) * 100 : 0)}
              </div>
            </div>
          </div>
        </section>

        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="space-y-1">
            <h2 className="text-sm font-semibold text-white">{t('dashboard.weeklyUptime')}</h2>
            <p className="text-xs text-white/60">{t('dashboard.weeklyUptimeDesc')}</p>
          </div>
          <div className="mt-4">
            <svg viewBox="0 0 260 110" className="h-40 w-full">
              <polyline
                fill="none"
                stroke="#38bdf8"
                strokeWidth="2"
                points={toPoints(normalize(weeklyUptime))}
              />
              <polygon
                fill="rgba(56,189,248,0.15)"
                points={`${toPoints(normalize(weeklyUptime))} 260,100 0,100`}
              />
            </svg>
          </div>
        </section>

        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="space-y-1">
            <h2 className="text-sm font-semibold text-white">{t('dashboard.alertsByModule')}</h2>
            <p className="text-xs text-white/60">{t('dashboard.alertsByModuleDesc')}</p>
          </div>
          <div className="mt-4 flex h-36 items-end gap-3">
            {alertsByModule.map((bar) => (
              <div key={bar.label} className="flex flex-1 flex-col items-center gap-2 text-[10px] text-white/60">
                <div className="flex h-24 w-full items-end gap-1">
                  <div className="flex-1 rounded-sm bg-sky-500/80" style={{ height: `${bar.primary * 10}%` }} />
                  <div className="flex-1 rounded-sm bg-orange-400/80" style={{ height: `${bar.secondary * 10}%` }} />
                </div>
                {bar.label}
              </div>
            ))}
          </div>
        </section>
      </div>

      {/* Observe Summary Widget */}
      {hasObserveAccess && selectedWorkspaceId && (
        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="text-sm font-semibold text-white">ShieldObserve Summary</h2>
              <p className="mt-1 text-xs text-white/60">Real-time monitoring status and alerts</p>
            </div>
            {observeData && (
              <span className="text-xs text-white/50">
                Last sync: {new Date().toLocaleTimeString()}
              </span>
            )}
          </div>
          
          {observeLoading ? (
            <div className="py-8 text-center text-sm text-white/60">Loading Observe data...</div>
          ) : observeData && (observeData.hostTotals?.up > 0 || (observeData.items?.length ?? 0) > 0) ? (
            <div className="space-y-4">
              {/* Summary Cards */}
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="mb-1 text-xs text-white/60">Hosts</div>
                  <div className="flex items-baseline gap-2">
                    <span className="text-lg font-semibold text-emerald-200">{observeData.hostTotals.up}</span>
                    <span className="text-xs text-white/50">Up</span>
                  </div>
                  <div className="mt-1 flex gap-2 text-xs text-white/50">
                    <span>{observeData.hostTotals.down} Down</span>
                    <span>•</span>
                    <span>{observeData.hostTotals.unreachable} Unreachable</span>
                  </div>
                </div>
                
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="mb-1 text-xs text-white/60">Services</div>
                  <div className="flex items-baseline gap-2">
                    <span className="text-lg font-semibold text-emerald-200">{observeData.serviceTotals.ok}</span>
                    <span className="text-xs text-white/50">OK</span>
                  </div>
                  <div className="mt-1 flex gap-2 text-xs">
                    <span className="text-yellow-200">{observeData.serviceTotals.warning} Warning</span>
                    <span className="text-white/50">•</span>
                    <span className="text-rose-200">{observeData.serviceTotals.critical} Critical</span>
                  </div>
                </div>
                
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="mb-1 text-xs text-white/60">Problems</div>
                  <div className="text-lg font-semibold text-rose-200">
                    {observeData.serviceTotals.warning + observeData.serviceTotals.critical + observeData.serviceTotals.unknown}
                  </div>
                  <div className="mt-1 text-xs text-white/50">Active issues</div>
                </div>
                
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="mb-1 text-xs text-white/60">Pending</div>
                  <div className="text-lg font-semibold text-sky-200">{observeData.serviceTotals.pending}</div>
                  <div className="mt-1 text-xs text-white/50">Awaiting check</div>
                </div>
              </div>
              
              {/* Top Problems List */}
              {observeData.items.length > 0 && (
                <div className="rounded-lg border border-white/10 bg-white/5 p-4">
                  <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-xs font-semibold text-white">Top Problems</h3>
                    <Link
                      to={`/app/workspaces/${selectedWorkspaceId}/observe/services?problems=1&status=critical,warning`}
                      className="text-xs text-sky-200 hover:text-sky-100 transition"
                    >
                      View All →
                    </Link>
                  </div>
                  <div className="space-y-2">
                    {observeData.items.slice(0, 5).map((item, index) => (
                      <button
                        key={`${item.host}-${item.service}-${index}`}
                        onClick={() => {
                          navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(item.host)}&status=${item.status}`)
                        }}
                        className="flex w-full items-center justify-between rounded border border-white/10 bg-white/5 px-3 py-2 text-left transition hover:bg-white/10"
                      >
                        <div className="flex-1">
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-white">{item.host}</span>
                            <span className="text-xs text-white/50">•</span>
                            <span className="text-xs text-white/70">{item.service}</span>
                          </div>
                          <div className="mt-0.5 text-[10px] text-white/50 line-clamp-1">{item.info}</div>
                        </div>
                        <div className="ml-3 flex items-center gap-2">
                          <span
                            className={`rounded-full border px-2 py-0.5 text-[10px] font-medium ${
                              item.status === 'critical'
                                ? 'border-rose-500/30 bg-rose-500/20 text-rose-200'
                                : item.status === 'warning'
                                ? 'border-yellow-500/30 bg-yellow-500/20 text-yellow-200'
                                : 'border-purple-500/30 bg-purple-500/20 text-purple-200'
                            }`}
                          >
                            {item.status.toUpperCase()}
                          </span>
                          <span className="text-[10px] text-white/50">
                            {Math.floor(item.durationSec / 3600)}h
                          </span>
                        </div>
                      </button>
                    ))}
                  </div>
                </div>
              )}
              
              {/* Quick Actions */}
              <div className="flex flex-wrap gap-2">
                <Link
                  to={`/app/workspaces/${selectedWorkspaceId}/observe/services`}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-xs font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
                >
                  Open Services
                </Link>
                <Link
                  to={`/app/workspaces/${selectedWorkspaceId}/observe/alert-management`}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-xs font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
                >
                  Open Alert Management
                </Link>
                <Link
                  to={`/app/workspaces/${selectedWorkspaceId}/observe/data-sources`}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-xs font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
                >
                  Configure Data Source
                </Link>
              </div>
            </div>
          ) : observeData ? (
            <div className="flex flex-col items-center justify-center py-12 px-6 text-center">
              <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full border border-white/20 bg-white/5 text-white/50">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <rect x="2" y="2" width="20" height="8" rx="2" ry="2" />
                  <rect x="2" y="14" width="20" height="8" rx="2" ry="2" />
                </svg>
              </div>
              <h3 className="mt-4 text-sm font-semibold text-white">No hosts in this workspace</h3>
              <p className="mt-1 max-w-xs text-xs text-white/60">
                Add hosts and services in Monitored Targets to see status and alerts here.
              </p>
              <div className="mt-4 flex flex-wrap items-center justify-center gap-3 text-xs">
                <Link
                  to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`}
                  className="inline-flex items-center gap-2 rounded-full bg-sky-500 px-4 py-2 font-semibold text-white transition hover:bg-sky-400"
                >
                  Add hosts
                </Link>
                <Link
                  to="/integrations"
                  className="inline-flex items-center gap-2 rounded-full border border-white/20 px-4 py-2 text-white/80 transition hover:bg-white/10"
                >
                  Configure alert webhooks
                </Link>
              </div>
              <p className="mt-3 text-[10px] text-white/40">
                Use <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/infrastructure-map`} className="text-sky-400 hover:underline">Infrastructure Map</Link> for topology.
              </p>
            </div>
          ) : (
            <div className="py-8 text-center text-sm text-white/60">No Observe data available</div>
          )}
        </section>
      )}

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-center">
        <h3 className="text-sm font-semibold text-white">{t('dashboard.noModulesTitle')}</h3>
        <p className="mt-2 text-xs text-white/60">
          {t('dashboard.noModulesDesc')}
        </p>
        <button
          type="button"
          className="mt-4 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
        >
          {t('dashboard.browseModules')}
        </button>
      </section>

      <section className="px-6 py-6 text-center text-white/70">
        <p className="text-sm font-semibold text-white/80">
          ShieldCore Control Center • Virtual IT Operations Platform • v2.1.0
        </p>
        <p className="mt-2 text-xs text-white/50">
          Unified monitoring and control for all your integrated PortShield SaaS modules
        </p>
      </section>
    </div>
  )
}

export default Dashboard

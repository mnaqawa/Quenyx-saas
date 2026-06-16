import { useMemo } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { useObserveServices } from '../hooks/useObserveData'
import type { ObserveServiceRow } from '../types/observe'

type ServiceStatus = ObserveServiceRow['status']

interface HostSummary {
  name: string
  rawHost: string
  total: number
  ok: number
  warning: number
  critical: number
  unknown: number
  pending: number
  worst: ServiceStatus
  lastCheckAt: string | null
}

const STATUS_RANK: Record<ServiceStatus, number> = {
  critical: 0,
  warning: 1,
  unknown: 2,
  pending: 3,
  ok: 4,
}

const worseOf = (a: ServiceStatus, b: ServiceStatus): ServiceStatus =>
  STATUS_RANK[a] <= STATUS_RANK[b] ? a : b

const statusBadgeClass = (status: ServiceStatus): string => {
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

const statusDotClass = (status: ServiceStatus): string => {
  switch (status) {
    case 'critical':
      return 'bg-rose-400'
    case 'warning':
      return 'bg-amber-400'
    case 'unknown':
      return 'bg-purple-400'
    case 'pending':
      return 'bg-sky-400'
    default:
      return 'bg-emerald-400'
  }
}

const formatRelative = (value: string | null): string => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  const diffMs = Date.now() - date.getTime()
  const mins = Math.floor(diffMs / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}

function Dashboard() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const {
    workspaces,
    workspacesError,
    isLoadingWorkspaces,
    selectedWorkspaceId,
    selectedWorkspaceRole,
    modulesWithAccess,
    allowedByKey,
  } = useWorkspaceContext()

  const observeModule = modulesWithAccess?.find((m) => m.key === 'qynsight')
  const hasObserveAccess = observeModule ? allowedByKey['qynsight'] : false
  const canManageOnboarding = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'

  // Real host/service data for the selected workspace (no fixtures, no synthetic fallback).
  const { data: observeData, loading: observeLoading } = useObserveServices({
    workspaceId: hasObserveAccess && selectedWorkspaceId ? selectedWorkspaceId : null,
    limit: 500,
    realDataOnly: true,
  })

  const hostPrefix = selectedWorkspaceId ? `ws${selectedWorkspaceId}-` : ''

  const hosts = useMemo<HostSummary[]>(() => {
    const items = observeData?.items ?? []
    const map = new Map<string, HostSummary>()
    for (const item of items) {
      const rawHost = item.host
      if (!rawHost) continue
      const name = rawHost.startsWith(hostPrefix) ? rawHost.slice(hostPrefix.length) : rawHost
      const existing =
        map.get(rawHost) ??
        {
          name,
          rawHost,
          total: 0,
          ok: 0,
          warning: 0,
          critical: 0,
          unknown: 0,
          pending: 0,
          worst: 'ok' as ServiceStatus,
          lastCheckAt: null,
        }
      existing.total += 1
      existing[item.status] += 1
      existing.worst = worseOf(existing.worst, item.status)
      if (item.lastCheckAt) {
        if (!existing.lastCheckAt || new Date(item.lastCheckAt) > new Date(existing.lastCheckAt)) {
          existing.lastCheckAt = item.lastCheckAt
        }
      }
      map.set(rawHost, existing)
    }
    return [...map.values()].sort((a, b) => STATUS_RANK[a.worst] - STATUS_RANK[b.worst] || a.name.localeCompare(b.name))
  }, [observeData?.items, hostPrefix])

  const topProblems = useMemo(() => {
    const items = observeData?.items ?? []
    return items
      .filter((item) => item.status === 'critical' || item.status === 'warning' || item.status === 'unknown')
      .sort((a, b) => STATUS_RANK[a.status] - STATUS_RANK[b.status])
      .slice(0, 6)
  }, [observeData?.items])

  const summary = useMemo(() => {
    const st = observeData?.serviceTotals
    const ht = observeData?.hostTotals
    const totalServices = st ? st.ok + st.warning + st.critical + st.unknown + st.pending : 0
    const problems = st ? st.warning + st.critical + st.unknown : 0
    const totalHosts = hosts.length || (ht ? ht.up + ht.down + ht.unreachable + ht.pending : 0)
    const healthPct = totalServices > 0 ? Math.round(((st?.ok ?? 0) / totalServices) * 100) : 0
    return { st, ht, totalServices, problems, totalHosts, healthPct }
  }, [observeData?.serviceTotals, observeData?.hostTotals, hosts.length])

  const hasHostData = summary.totalHosts > 0 || (observeData?.items?.length ?? 0) > 0

  // ---- Top-level gating states -------------------------------------------------

  if (isLoadingWorkspaces) {
    return <div className="text-sm text-white/60">{t('common.loadingDashboard')}</div>
  }

  if (workspacesError) {
    return (
      <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        {workspacesError}
      </div>
    )
  }

  if (workspaces.length === 0) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
          <p className="font-semibold">{t('dashboard.selectWorkspaceTitle')}</p>
          <p className="mt-1 text-xs text-sky-200/80">{t('dashboard.noWorkspacesDesc')}</p>
        </div>
        <Link
          to="/app/workspaces"
          className="inline-flex rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
        >
          {t('getStarted.createWorkspace')}
        </Link>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight text-white">{t('dashboard.title')}</h1>
          <p className="text-sm text-white/60">{t('dashboard.subtitle')}</p>
        </div>
      </div>

      {/* Role-based onboarding (owner/admin only) */}
      {selectedWorkspaceId && canManageOnboarding && (
        <section className="rounded-2xl border border-sky-500/20 bg-sky-500/5 p-5">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h2 className="text-sm font-semibold text-white">{t('dashboard.onboardingTitle')}</h2>
              <p className="mt-1 text-xs text-white/70">{t('dashboard.onboardingDesc')}</p>
            </div>
            <span className="rounded-full border border-sky-400/40 bg-sky-500/10 px-3 py-1 text-[11px] font-medium text-sky-200">
              {selectedWorkspaceRole === 'owner' ? 'Owner' : 'Admin'}
            </span>
          </div>
          <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <Link to="/app/workspaces" className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/80 transition hover:bg-white/10">
              {t('dashboard.actionManageWorkspaces')}
            </Link>
            <Link to="/settings/members" className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/80 transition hover:bg-white/10">
              {t('dashboard.actionAssignUsers')}
            </Link>
            <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`} className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/80 transition hover:bg-white/10">
              {t('dashboard.actionAddHosts')}
            </Link>
            <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`} className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/80 transition hover:bg-white/10">
              {t('dashboard.actionVerifyMonitoring')}
            </Link>
          </div>
        </section>
      )}

      {/* No workspace selected */}
      {!selectedWorkspaceId ? (
        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center">
          <h3 className="text-sm font-semibold text-white">{t('dashboard.selectWorkspaceTitle')}</h3>
          <p className="mx-auto mt-2 max-w-sm text-xs text-white/60">{t('dashboard.selectWorkspaceDesc')}</p>
          <Link
            to="/app/workspaces"
            className="mt-4 inline-flex rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
          >
            {t('nav.projects')}
          </Link>
        </section>
      ) : !hasObserveAccess ? (
        /* QynSight not enabled for this workspace */
        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center">
          <h3 className="text-sm font-semibold text-white">{t('dashboard.moduleLockedTitle')}</h3>
          <p className="mx-auto mt-2 max-w-sm text-xs text-white/60">{t('dashboard.moduleLockedDesc')}</p>
          <Link
            to="/subscriptions"
            className="mt-4 inline-flex rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
          >
            {t('nav.subscriptions')}
          </Link>
        </section>
      ) : observeLoading ? (
        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-sm text-white/60">
          {t('common.loadingDashboard')}
        </section>
      ) : !hasHostData ? (
        /* No hosts -> no data (real empty state, no synthetic charts) */
        <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-12 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full border border-white/20 bg-white/5 text-white/50">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="2" width="20" height="8" rx="2" ry="2" />
              <rect x="2" y="14" width="20" height="8" rx="2" ry="2" />
              <line x1="6" y1="6" x2="6.01" y2="6" />
              <line x1="6" y1="18" x2="6.01" y2="18" />
            </svg>
          </div>
          <h3 className="mt-4 text-sm font-semibold text-white">{t('dashboard.noHostsTitle')}</h3>
          <p className="mx-auto mt-1 max-w-md text-xs text-white/60">{t('dashboard.noHostsDesc')}</p>
          <div className="mt-5 flex flex-wrap items-center justify-center gap-3 text-xs">
            <Link
              to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`}
              className="inline-flex items-center gap-2 rounded-full bg-sky-500 px-4 py-2 font-semibold text-white transition hover:bg-sky-400"
            >
              {t('dashboard.actionAddHosts')}
            </Link>
            <Link
              to="/integrations"
              className="inline-flex items-center gap-2 rounded-full border border-white/20 px-4 py-2 text-white/80 transition hover:bg-white/10"
            >
              {t('dashboard.actionConfigureWebhooks')}
            </Link>
          </div>
        </section>
      ) : (
        <>
          {/* Real workspace summary (derived from live host/service data) */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
            <div className="flex items-center justify-between gap-4">
              <div>
                <h2 className="text-sm font-semibold text-white">{t('dashboard.overviewTitle')}</h2>
                <p className="mt-1 text-xs text-white/60">{t('dashboard.overviewDesc')}</p>
              </div>
              <Link
                to={`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`}
                className="text-xs font-medium text-sky-300 transition hover:text-sky-200"
              >
                {t('dashboard.viewMonitoring')} →
              </Link>
            </div>
            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div className="rounded-xl border border-white/10 bg-[#0b1118] p-4">
                <p className="text-xs text-white/60">{t('dashboard.metricHosts')}</p>
                <p className="mt-2 text-2xl font-semibold text-white">{summary.totalHosts}</p>
                <p className="mt-1 text-[11px] text-white/50">
                  {summary.ht?.up ?? 0} {t('dashboard.up')} · {summary.ht?.down ?? 0} {t('dashboard.down')} · {summary.ht?.unreachable ?? 0} {t('dashboard.unreachable')}
                </p>
              </div>
              <div className="rounded-xl border border-white/10 bg-[#0b1118] p-4">
                <p className="text-xs text-white/60">{t('dashboard.metricServices')}</p>
                <p className="mt-2 text-2xl font-semibold text-white">{summary.totalServices}</p>
                <p className="mt-1 text-[11px] text-white/50">
                  <span className="text-emerald-300">{summary.st?.ok ?? 0} {t('dashboard.ok')}</span> · <span className="text-amber-300">{summary.st?.warning ?? 0} {t('dashboard.warning')}</span> · <span className="text-rose-300">{summary.st?.critical ?? 0} {t('dashboard.critical')}</span>
                </p>
              </div>
              <div className="rounded-xl border border-white/10 bg-[#0b1118] p-4">
                <p className="text-xs text-white/60">{t('dashboard.metricProblems')}</p>
                <p className={`mt-2 text-2xl font-semibold ${summary.problems > 0 ? 'text-rose-300' : 'text-emerald-300'}`}>
                  {summary.problems}
                </p>
                <p className="mt-1 text-[11px] text-white/50">{t('dashboard.activeIssues')}</p>
              </div>
              <div className="rounded-xl border border-white/10 bg-[#0b1118] p-4">
                <p className="text-xs text-white/60">{t('dashboard.metricHealth')}</p>
                <p className="mt-2 text-2xl font-semibold text-white">{summary.healthPct}%</p>
                <p className="mt-1 text-[11px] text-white/50">{t('dashboard.servicesHealthy')}</p>
              </div>
            </div>
          </section>

          {/* Per-host boxes */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-sm font-semibold text-white">{t('dashboard.hostsTitle')}</h2>
                <p className="mt-1 text-xs text-white/60">{t('dashboard.hostsDesc')}</p>
              </div>
              <Link
                to={`/app/workspaces/${selectedWorkspaceId}/observe/services`}
                className="text-xs font-medium text-sky-300 transition hover:text-sky-200"
              >
                {t('dashboard.viewAll')} →
              </Link>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {hosts.map((host) => (
                <button
                  key={host.rawHost}
                  type="button"
                  onClick={() =>
                    navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(host.name)}`)
                  }
                  className="rounded-xl border border-white/10 bg-[#0b1118] p-4 text-left transition hover:border-white/25 hover:bg-white/[0.04]"
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2">
                      <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${statusDotClass(host.worst)}`} />
                      <span className="truncate text-sm font-semibold text-white">{host.name}</span>
                    </div>
                    <span className={`shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase ${statusBadgeClass(host.worst)}`}>
                      {host.worst}
                    </span>
                  </div>
                  <p className="mt-1 text-[11px] text-white/45">
                    {host.total} {t('dashboard.services')} · {t('dashboard.lastCheck')} {formatRelative(host.lastCheckAt)}
                  </p>
                  <div className="mt-3 flex flex-wrap gap-1.5 text-[10px]">
                    {host.ok > 0 && (
                      <span className="rounded border border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0.5 text-emerald-200">{host.ok} {t('dashboard.ok')}</span>
                    )}
                    {host.warning > 0 && (
                      <span className="rounded border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-amber-200">{host.warning} {t('dashboard.warning')}</span>
                    )}
                    {host.critical > 0 && (
                      <span className="rounded border border-rose-500/30 bg-rose-500/10 px-1.5 py-0.5 text-rose-200">{host.critical} {t('dashboard.critical')}</span>
                    )}
                    {host.unknown > 0 && (
                      <span className="rounded border border-purple-500/30 bg-purple-500/10 px-1.5 py-0.5 text-purple-200">{host.unknown} {t('dashboard.unknown')}</span>
                    )}
                    {host.pending > 0 && (
                      <span className="rounded border border-sky-500/30 bg-sky-500/10 px-1.5 py-0.5 text-sky-200">{host.pending} {t('dashboard.pending')}</span>
                    )}
                  </div>
                </button>
              ))}
            </div>
          </section>

          {/* Top problems (real) */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-sm font-semibold text-white">{t('dashboard.topProblems')}</h2>
                <p className="mt-1 text-xs text-white/60">{t('dashboard.topProblemsDesc')}</p>
              </div>
              <Link
                to={`/app/workspaces/${selectedWorkspaceId}/observe/services?problems=1&status=critical,warning`}
                className="text-xs font-medium text-sky-300 transition hover:text-sky-200"
              >
                {t('dashboard.viewAll')} →
              </Link>
            </div>
            {topProblems.length === 0 ? (
              <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-6 text-center text-xs text-emerald-200">
                {t('dashboard.noProblems')}
              </div>
            ) : (
              <div className="space-y-2">
                {topProblems.map((item, index) => (
                  <button
                    key={`${item.host}-${item.service}-${index}`}
                    type="button"
                    onClick={() =>
                      navigate(`/app/workspaces/${selectedWorkspaceId}/observe/services?q=${encodeURIComponent(item.host.startsWith(hostPrefix) ? item.host.slice(hostPrefix.length) : item.host)}&status=${item.status}`)
                    }
                    className="flex w-full items-center justify-between rounded border border-white/10 bg-white/5 px-3 py-2 text-left transition hover:bg-white/10"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="truncate text-xs font-medium text-white">
                          {item.host.startsWith(hostPrefix) ? item.host.slice(hostPrefix.length) : item.host}
                        </span>
                        <span className="text-xs text-white/50">•</span>
                        <span className="truncate text-xs text-white/70">{item.service}</span>
                      </div>
                      {item.info && <div className="mt-0.5 line-clamp-1 text-[10px] text-white/45">{item.info}</div>}
                    </div>
                    <span className={`ml-3 shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-medium uppercase ${statusBadgeClass(item.status)}`}>
                      {item.status}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </section>
        </>
      )}
    </div>
  )
}

export default Dashboard

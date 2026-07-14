import { useState, useMemo, useEffect, Fragment, useCallback } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { useObserveWorkspaceId } from '../../hooks/useObserveWorkspaceId'
import { useObserveServices } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { QuenyxAiButton } from '../../components/observe/intelligence/QuenyxAiButton'
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { ServiceDetailsDrawer } from '../../components/observe/ServiceDetailsDrawer'
import { useObserveAutoRefresh } from '../../hooks/useObserveAutoRefresh'
import { formatObserveDuration, resolveObserveDurationSec } from '../../lib/observeDuration'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiAgentAvailable } from '../../hooks/useAiAgentAvailable'
import { useObserveAccess } from '../../hooks/useObserveAccess'
import { ObserveLoadError } from '../../components/observe/ObserveLoadError'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import type { AIAgentSeed } from '../../types/aiAgent'
import { observeService } from '../../services/observeService'
import type { ObserveServiceRow } from '../../types/observe'
import {
  getPollAgeSeconds,
  shouldShowObserveStaleBanner,
} from '../../lib/observeFreshness'

const statusOptions = ['ok', 'warning', 'critical', 'unknown', 'pending'] as const
const limitOptions = [25, 50, 100, 200]

function checkSortOrder(serviceName: string): number {
  const n = serviceName.toLowerCase()
  if (n.includes('cpu')) return 0
  if (n.includes('memory') || n.includes('ram')) return 1
  if (n.includes('disk') || n.includes('storage')) return 2
  if (n.includes('network')) return 3
  if (n.includes('load')) return 4
  if (n.includes('ping') || n.includes('live')) return 5
  return 50
}

function treeBranch(index: number, total: number): string {
  if (total <= 1) return '└ '
  return index === total - 1 ? '└ ' : '├ '
}

function formatDateTime(dateString: string | null | undefined, locale: string): string {
  if (dateString == null || String(dateString).trim() === '') return '—'
  const date = new Date(dateString)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString(locale === 'ar' ? 'ar' : 'en-US', {
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
  const { t, language } = useLanguage()
  const selectedWorkspaceId = useObserveWorkspaceId()
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
  const [drawerService, setDrawerService] = useState<ObserveServiceRow | null>(null)
  const [rechecking, setRechecking] = useState(false)
  const [recheckError, setRecheckError] = useState<string | null>(null)
  const [aiOpen, setAiOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)
  const aiAvailable = useAiAgentAvailable(selectedWorkspaceId)
  const { canRunOperations } = useObserveAccess()

  // Sync state changes to URL query params (omit searchParams from deps to avoid replace loops).
  useEffect(() => {
    const params = new URLSearchParams()
    if (searchQuery) params.set('q', searchQuery)
    if (selectedStatuses.length > 0) params.set('status', selectedStatuses.join(','))
    if (limit !== 100) params.set('limit', limit.toString())
    if (problemsOnly) params.set('problems', '1')

    setSearchParams(params, { replace: true })
  }, [searchQuery, selectedStatuses, limit, problemsOnly, setSearchParams])

  const [refreshKey, setRefreshKey] = useState(0)
  
  const { data, loading, refreshing, error } = useObserveServices({
    workspaceId: selectedWorkspaceId,
    q: searchQuery,
    statuses: selectedStatuses.length > 0 ? selectedStatuses : undefined,
    limit,
    problemsOnly,
    refreshKey,
  })

  const triggerRefresh = useCallback(() => {
    setRefreshKey((prev) => prev + 1)
  }, [])

  const {
    interval,
    setInterval,
    markUpdated,
    refreshNow,
    secondsAgo,
  } = useObserveAutoRefresh(triggerRefresh, !!selectedWorkspaceId)

  useEffect(() => {
    if (!loading && !refreshing && data) markUpdated()
  }, [loading, refreshing, data, refreshKey, markUpdated])

  const handleRecheck = async () => {
    if (!selectedWorkspaceId || !canRunOperations) return
    setRechecking(true)
    setRecheckError(null)
    try {
      await observeService.runChecks(Number(selectedWorkspaceId))
      // Checks run asynchronously — keep the indicator visible while the job runs.
      triggerRefresh()
      window.setTimeout(() => triggerRefresh(), 4000)
      window.setTimeout(() => triggerRefresh(), 8000)
      markUpdated()
    } catch (e) {
      setRecheckError(e instanceof Error ? e.message : t('services.recheckError'))
    } finally {
      window.setTimeout(() => setRechecking(false), 8000)
    }
  }

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

  // Group services by host with stable check ordering (platform metrics first)
  const groupedByHost = useMemo(() => {
    if (!data?.items?.length) return []
    const byHost = new Map<string, ObserveServiceRow[]>()
    for (const item of data.items) {
      const list = byHost.get(item.host) ?? []
      list.push(item)
      byHost.set(item.host, list)
    }
    return Array.from(byHost.entries()).map(([host, items]) => ({
      host,
      items: [...items].sort(
        (a, b) => checkSortOrder(a.service) - checkSortOrder(b.service) || a.service.localeCompare(b.service),
      ),
    }))
  }, [data])

  // Display host name without workspace prefix for section headers (e.g. ws84-Quenyx-DEV-Platform → Quenyx-DEV-Platform)
  const hostDisplayName = (host: string) => {
    if (!selectedWorkspaceId) return host
    const prefix = `ws${selectedWorkspaceId}-`
    return host.startsWith(prefix) ? host.slice(prefix.length) : host
  }

  if (loading && !data) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-sm text-white/60">{t('common.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('services.title')} subtitle={t('services.subtitle')} />
        <ObserveLoadError
          message={t('observe.error.services')}
          retryLabel={t('observe.loadError.retry')}
          onRetry={() => {
            triggerRefresh()
            refreshNow()
          }}
        />
      </div>
    )
  }

  if (!data) {
    return (
      <div className="rounded-lg border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/60">
        {t('services.noData')}
      </div>
    )
  }

  const showStaleBanner = shouldShowObserveStaleBanner(data)
  const pollAgeSeconds = getPollAgeSeconds(data.last_poll_at)
  const freshnessSecondsAgo = pollAgeSeconds ?? secondsAgo

  return (
    <div className="space-y-6">
      {(data.engine_unreachable || showStaleBanner) && (
        <div className={`rounded-lg border px-4 py-3 text-sm ${
          data.engine_unreachable
            ? 'border-rose-500/30 bg-rose-500/10 text-rose-100'
            : 'border-yellow-500/30 bg-yellow-500/10 text-yellow-100'
        }`}>
          {data.engine_unreachable ? (
            <span>
              {t('services.engineUnreachable')}
              <span className="block mt-1 opacity-90">
                {t('services.engineUnreachableReason')}{' '}
                {data.engine_unreachable_reason || t('services.engineUnreachableHint')}
              </span>
            </span>
          ) : (
            <span>
              {t('services.dataStale').replace(
                '{time}',
                data.last_poll_at ? new Date(data.last_poll_at).toLocaleString() : t('rtm.never'),
              )}
            </span>
          )}
        </div>
      )}

      <PageHeader
        title={t('services.title')}
        subtitle={t('services.subtitle')}
        actions={
          <>
            <QuenyxAiButton size="md" label={t('ai.action.analyze')} question={t('opsIntel.q.services')} />
            <ObservePageToolbar
              interval={interval}
              onIntervalChange={setInterval}
              secondsAgo={freshnessSecondsAgo}
              onRefresh={() => {
                triggerRefresh()
                refreshNow()
              }}
              refreshing={loading || refreshing || rechecking}
              disabled={false}
              onSettings={
                selectedWorkspaceId
                  ? () => navigate(`/app/workspaces/${selectedWorkspaceId}/observe/targets`)
                  : undefined
              }
            />
          </>
        }
      />

      {recheckError ? (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {recheckError}
        </div>
      ) : null}

      {rechecking ? (
        <div className="flex items-center gap-2 rounded-lg border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
          <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-sky-300" />
          {t('services.rechecking')} Statuses will refresh automatically when checks finish.
        </div>
      ) : null}

      {/* Summary Totals */}
      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-lg border border-white/10 bg-white/5 p-4">
          <h3 className="mb-3 text-xs font-semibold text-white/70">{t('services.hostStatusTotals')}</h3>
          <div className="grid grid-cols-4 gap-2">
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.up')}</div>
              <div className="rounded bg-emerald-500/20 px-2 py-1 text-center text-sm font-semibold text-emerald-200">
                {data.hostTotals.up}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.down')}</div>
              <div className="rounded bg-gray-500/20 px-2 py-1 text-center text-sm font-semibold text-gray-200">
                {data.hostTotals.down}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.unreachable')}</div>
              <div className="rounded bg-gray-500/20 px-2 py-1 text-center text-sm font-semibold text-gray-200">
                {data.hostTotals.unreachable}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.pending')}</div>
              <div className="rounded bg-gray-500/20 px-2 py-1 text-center text-sm font-semibold text-gray-200">
                {data.hostTotals.pending}
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/5 p-4">
          <h3 className="mb-3 text-xs font-semibold text-white/70">{t('services.serviceCheckStatusTotals')}</h3>
          <div className="grid grid-cols-6 gap-2">
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.ok')}</div>
              <div className="rounded bg-emerald-500/20 px-2 py-1 text-center text-sm font-semibold text-emerald-200">
                {data.serviceTotals.ok}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.warning')}</div>
              <div className="rounded bg-yellow-500/20 px-2 py-1 text-center text-sm font-semibold text-yellow-200">
                {data.serviceTotals.warning}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.unknown')}</div>
              <div className="rounded bg-purple-500/20 px-2 py-1 text-center text-sm font-semibold text-purple-200">
                {data.serviceTotals.unknown}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.critical')}</div>
              <div className="rounded bg-rose-500/20 px-2 py-1 text-center text-sm font-semibold text-rose-200">
                {data.serviceTotals.critical}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.pending')}</div>
              <div className="rounded bg-sky-500/20 px-2 py-1 text-center text-sm font-semibold text-sky-200">
                {data.serviceTotals.pending}
              </div>
            </div>
            <div>
              <div className="mb-1 text-xs text-white/60">{t('services.totals.unreachable')}</div>
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
          placeholder={t('services.searchPlaceholder')}
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="flex-1 min-w-[200px] rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40"
        />

        <div className="flex items-center gap-2">
          <span className="text-xs text-white/60">{t('services.filter.status')}:</span>
          {statusOptions.map((status) => (
            <button
              key={status}
              onClick={() => toggleStatus(status)}
              disabled={false}
              className={`rounded-full border px-2 py-1 text-[10px] font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${
                selectedStatuses.includes(status)
                  ? 'border-sky-500 bg-sky-500/20 text-sky-200'
                  : 'border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
              }`}
            >
              {t(`status.service.${status}` as 'status.service.ok')}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs text-white/60">{t('services.filter.limit')}:</span>
          <select
            value={limit}
            onChange={(e) => setLimit(Number(e.target.value))}
            disabled={false}
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
            onClick={() => setProblemsOnly(false)}
            disabled={false}
            className={`rounded px-2 py-1 text-[10px] font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${
              !problemsOnly
                ? 'bg-sky-500/20 text-sky-200 border border-sky-500/30'
                : 'border border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
            }`}
          >
            {t('services.filter.allTypes')} ({data.items.length})
          </button>
          <button
            onClick={() => setProblemsOnly(true)}
            disabled={false}
            className={`rounded px-2 py-1 text-[10px] font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${
              problemsOnly
                ? 'bg-sky-500/20 text-sky-200 border border-sky-500/30'
                : 'border border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
            }`}
          >
            {t('services.filter.allProblems')} ({data.serviceTotals.warning + data.serviceTotals.critical + data.serviceTotals.unknown})
          </button>
        </div>
      </div>

      {/* Main Table */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">{t('services.detailsTitle')}</h3>
        <div className="overflow-x-auto">
          <table className="w-full border-collapse">
            <thead className="sticky top-0 bg-[#0f151d]">
              <tr className="border-b border-white/10">
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">{t('services.col.checkName')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70 w-28">{t('services.col.status')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">{t('services.col.lastCheck')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">{t('services.col.duration')}</th>
                <th className="px-3 py-2 text-center text-xs font-semibold text-white/70 w-20">{t('services.col.attempts')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-white/70">{t('services.col.output')}</th>
                <th className="px-3 py-2 text-right text-xs font-semibold text-white/70 w-28">{t('services.col.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {data.items.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-3 py-8 text-center text-sm text-white/60">
                    {t('services.empty')}
                  </td>
                </tr>
              ) : (
                groupedByHost.map(({ host, items }) => (
                  <Fragment key={host}>
                    <tr className="border-b border-white/10 bg-white/5">
                      <td colSpan={7} className="px-3 py-2.5 text-xs font-semibold text-white/90">
                        <span className="text-white/50" aria-hidden>
                          ▼{' '}
                        </span>
                        {hostDisplayName(host)}
                        <span className="ms-2 font-normal text-white/50">
                          ({t('services.hostServiceCount').replace('{count}', String(items.length))})
                        </span>
                      </td>
                    </tr>
                    {items.map((item, index) => {
                      const rowKey = `${item.host}-${item.service}-${index}`
                      const output = item.pluginOutput || item.info || item.longPluginOutput || '—'
                      return (
                        <tr key={rowKey} className={`border-b border-white/5 ${getRowBgColor(item.status)}`}>
                          <td className="px-3 py-2.5 text-[13px]">
                            <span className="font-mono text-white/40" aria-hidden>
                              {treeBranch(index, items.length)}
                            </span>
                            {item.service}
                          </td>
                          <td className="px-3 py-2.5">
                            <span
                              className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-medium ${getStatusColor(item.status)}`}
                            >
                              {item.status.toUpperCase()}
                            </span>
                          </td>
                          <td className="px-3 py-2.5 text-[13px] text-white/70 font-mono tabular-nums">
                            {formatDateTime(item.lastCheckAt, language)}
                          </td>
                          <td className="px-3 py-2.5 text-[13px] text-white/70">
                            {formatObserveDuration(
                              resolveObserveDurationSec(item.durationSec, item.lastStateChangeAt),
                            )}
                          </td>
                          <td className="px-3 py-2.5 text-center text-[13px] text-white/70 font-mono">{item.attempt}</td>
                          <td className="px-3 py-2.5 text-[13px] text-white/70">
                            <div className="line-clamp-2" title={output}>
                              {output}
                            </div>
                          </td>
                          <td className="px-3 py-2.5">
                            <div className="flex items-center justify-end gap-2">
                              <button
                                type="button"
                                onClick={() => setDrawerService(item)}
                                className="rounded border border-white/10 bg-white/5 px-2 py-1 text-[10px] text-white/80 hover:bg-white/10"
                              >
                                {t('services.action.view')}
                              </button>
                              <button
                                type="button"
                                onClick={() => {
                                  setDrawerService(item)
                                  void handleRecheck()
                                }}
                                disabled={!canRunOperations || rechecking}
                                title={t('services.action.runAllChecksHint')}
                                className="inline-flex items-center gap-1.5 rounded border border-sky-500/30 bg-sky-500/10 px-2 py-1 text-[10px] text-sky-200 hover:bg-sky-500/20 disabled:opacity-50"
                              >
                                {rechecking ? (
                                  <>
                                    <span className="h-2 w-2 animate-pulse rounded-full bg-sky-300" />
                                    {t('services.rechecking')}
                                  </>
                                ) : (
                                  t('services.action.recheck')
                                )}
                              </button>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </Fragment>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {selectedWorkspaceId && drawerService ? (
        <ServiceDetailsDrawer
          open={!!drawerService}
          onClose={() => setDrawerService(null)}
          workspaceId={Number(selectedWorkspaceId)}
          service={drawerService}
          onRecheck={canRunOperations ? handleRecheck : undefined}
          rechecking={rechecking}
          showAiExplain={aiAvailable}
          onExplainCheck={
            aiAvailable
              ? () => {
                  setAiSeed({
                    id: Date.now(),
                    agent: 'anomaly_detector',
                    question: `Explain the service check result for ${drawerService.service} on ${drawerService.host}. Status: ${drawerService.status}. Output: ${drawerService.pluginOutput || drawerService.info || 'none'}.`,
                    autoSend: true,
                    quick: true,
                    context: {
                      source: 'qynsight_services',
                      host: drawerService.host,
                      metrics: {
                        service: drawerService.service,
                        status: drawerService.status,
                      },
                    },
                  })
                  setAiOpen(true)
                }
              : undefined
          }
        />
      ) : null}

      {selectedWorkspaceId ? (
        <AIAgentDrawer
          open={aiOpen}
          workspaceId={Number(selectedWorkspaceId)}
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

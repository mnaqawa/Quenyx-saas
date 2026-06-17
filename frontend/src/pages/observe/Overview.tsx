import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '../../components/observe/PageHeader'
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { StatCard } from '../../components/observe/StatCard'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveServices } from '../../hooks/useObserveData'
import { useObserveAutoRefresh } from '../../hooks/useObserveAutoRefresh'
import { observeService } from '../../services/observeService'
import { buildHostRuntimeMap, classifyHostHealth } from '../../lib/observeHostUtils'
import type { AlertHistoryEvent, CapacityPlanningResponse } from '../../types/observe'
import { buildCollectingPanelProps, CollectingHistoricalDataPanel } from '../../components/observe/CollectingHistoricalDataPanel'

export default function Overview() {
  const { t } = useLanguage()
  const { selectedWorkspaceId } = useWorkspaceContext()
  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const [refreshKey, setRefreshKey] = useState(0)
  const [recentAlerts, setRecentAlerts] = useState<AlertHistoryEvent[]>([])
  const [capacity, setCapacity] = useState<CapacityPlanningResponse | null>(null)
  const [alertsLoading, setAlertsLoading] = useState(false)

  const { data, loading, error } = useObserveServices({
    workspaceId: selectedWorkspaceId,
    limit: 500,
    refreshKey,
    realDataOnly: true,
  })

  const refreshAll = useCallback(() => {
    setRefreshKey((k) => k + 1)
  }, [])

  const { interval, setInterval, markUpdated, refreshNow, secondsAgo } = useObserveAutoRefresh(
    refreshAll,
    !!wsId,
  )

  useEffect(() => {
    if (refreshKey > 0) markUpdated()
  }, [refreshKey, markUpdated])

  useEffect(() => {
    if (!wsId) return
    setAlertsLoading(true)
    Promise.all([
      observeService.getAlertHistory(wsId, { limit: 15, status: 'open' }),
      observeService.getCapacityPlanning(wsId, '30d'),
    ])
      .then(([history, cap]) => {
        setRecentAlerts(Array.isArray(history) ? history.slice(0, 10) : [])
        setCapacity(cap)
        markUpdated()
      })
      .catch(() => {
        setRecentAlerts([])
        setCapacity(null)
      })
      .finally(() => setAlertsLoading(false))
  }, [wsId, refreshKey, markUpdated])

  const hostMap = useMemo(
    () => buildHostRuntimeMap(data?.items ?? [], wsId ?? undefined),
    [data?.items, wsId],
  )

  const hostStats = useMemo(() => {
    let healthy = 0
    let warning = 0
    let critical = 0
    for (const summary of hostMap.values()) {
      const bucket = classifyHostHealth(summary.status)
      if (bucket === 'healthy') healthy += 1
      else if (bucket === 'warning') warning += 1
      else critical += 1
    }
    const total = hostMap.size
    const availabilityPct = total > 0 ? Math.round((healthy / total) * 100) : null
    return { healthy, warning, critical, total, availabilityPct }
  }, [hostMap])

  const openAlerts = recentAlerts.filter((a) => a.status === 'open' || a.status === 'active').length

  const riskScore = capacity?.health?.risk_score ?? capacity?.summary?.capacity_risk_score ?? null

  const topHosts = useMemo(() => {
    const perfHosts = capacity?.resource_analysis?.top_cpu_consumers ?? []
    if (perfHosts.length > 0) {
      return perfHosts.slice(0, 5).map((h) => ({
        name: h.host,
        value: h.utilization_pct,
        metric: t('cap.cpu'),
      }))
    }
    const fromServices = [...hostMap.entries()]
      .map(([name, summary]) => ({
        name,
        value: summary.critical + summary.warning,
        metric: t('overview.checkIssues'),
      }))
      .filter((h) => h.value > 0)
      .sort((a, b) => b.value - a.value)
      .slice(0, 5)
    return fromServices
  }, [capacity, hostMap, t])

  const capacityPanel =
    capacity && capacity.meta?.data_available !== true
      ? buildCollectingPanelProps(
          capacity.diagnostics,
          capacity.meta?.history_points,
          capacity.health?.data_confidence,
          t,
        )
      : null

  const basePath = wsId ? `/app/workspaces/${wsId}/observe` : '#'

  if (!wsId) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-sm text-white/60">
        {t('overview.selectWorkspace')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('overview.title')}
        subtitle={t('overview.subtitle')}
        actions={
          <ObservePageToolbar
            interval={interval}
            onIntervalChange={setInterval}
            secondsAgo={secondsAgo}
            onRefresh={() => {
              refreshAll()
              refreshNow()
            }}
            refreshing={loading || alertsLoading}
          />
        }
      />

      {error ? (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {error}
        </div>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <StatCard title={t('overview.kpi.healthyHosts')} value={String(hostStats.healthy)} detail={t('overview.kpi.hosts')} />
        <StatCard title={t('overview.kpi.warningHosts')} value={String(hostStats.warning)} detail={t('overview.kpi.hosts')} />
        <StatCard title={t('overview.kpi.criticalHosts')} value={String(hostStats.critical)} detail={t('overview.kpi.hosts')} />
        <StatCard title={t('overview.kpi.openAlerts')} value={String(openAlerts)} detail={t('alerts.tab.history')} />
        <StatCard
          title={t('overview.kpi.availability')}
          value={hostStats.availabilityPct != null ? `${hostStats.availabilityPct}%` : '—'}
          detail={hostStats.total > 0 ? `${hostStats.total} ${t('overview.kpi.hosts')}` : t('overview.noHosts')}
        />
        <StatCard
          title={t('overview.kpi.capacityRisk')}
          value={riskScore != null ? String(riskScore) : '—'}
          detail={capacity?.health?.data_confidence ? String(capacity.health.data_confidence) : t('cap.confidence.noData')}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="mb-4 flex items-center justify-between gap-2">
            <h3 className="text-sm font-semibold text-white">{t('overview.healthSummary')}</h3>
            <Link to={`${basePath}/real-time-monitoring`} className="text-xs text-sky-400 hover:text-sky-300">
              {t('overview.viewMonitoring')}
            </Link>
          </div>
          {loading ? (
            <p className="text-sm text-white/50">{t('common.loading')}</p>
          ) : hostStats.total === 0 ? (
            <p className="text-sm text-white/60">{t('overview.noHosts')}</p>
          ) : (
            <div className="grid grid-cols-3 gap-3 text-center text-xs">
              <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3">
                <p className="text-emerald-200/80">{t('overview.kpi.healthyHosts')}</p>
                <p className="mt-1 text-xl font-semibold text-emerald-200">{hostStats.healthy}</p>
              </div>
              <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-3">
                <p className="text-amber-200/80">{t('overview.kpi.warningHosts')}</p>
                <p className="mt-1 text-xl font-semibold text-amber-200">{hostStats.warning}</p>
              </div>
              <div className="rounded-lg border border-rose-500/20 bg-rose-500/10 p-3">
                <p className="text-rose-200/80">{t('overview.kpi.criticalHosts')}</p>
                <p className="mt-1 text-xl font-semibold text-rose-200">{hostStats.critical}</p>
              </div>
            </div>
          )}
          <div className="mt-4 grid grid-cols-2 gap-2 text-xs text-white/60">
            <p>
              {t('services.serviceCheckStatusTotals')}: {data?.serviceTotals.ok ?? 0} OK · {data?.serviceTotals.warning ?? 0}{' '}
              {t('cap.status.warning')}
            </p>
            <p>
              {t('overview.kpi.capacityRisk')}: {riskScore != null ? riskScore : '—'}
            </p>
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="mb-4 flex items-center justify-between gap-2">
            <h3 className="text-sm font-semibold text-white">{t('overview.recentAlerts')}</h3>
            <Link to={`${basePath}/alert-management`} className="text-xs text-sky-400 hover:text-sky-300">
              {t('overview.viewAlerts')}
            </Link>
          </div>
          {alertsLoading ? (
            <p className="text-sm text-white/50">{t('common.loading')}</p>
          ) : recentAlerts.length === 0 ? (
            <p className="text-sm text-white/60">{t('alerts.historyEmpty')}</p>
          ) : (
            <ul className="space-y-2">
              {recentAlerts.map((alert) => (
                <li key={alert.id} className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  <p className="font-medium text-white">{alert.title}</p>
                  <p className="mt-1 text-white/50">
                    {alert.host_name ?? '—'} · {alert.severity} · {alert.status}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {capacityPanel ? (
        <CollectingHistoricalDataPanel {...capacityPanel} />
      ) : capacity?.meta?.data_available ? (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
          <div className="mb-3 flex items-center justify-between gap-2">
            <h3 className="text-sm font-semibold text-white">{t('overview.capacitySummary')}</h3>
            <Link to={`${basePath}/capacity-planning`} className="text-xs text-sky-400 hover:text-sky-300">
              {t('overview.viewCapacity')}
            </Link>
          </div>
          <p className="text-sm text-white/70">
            {capacity.health?.recommended_action ?? capacity.health?.primary_risk ?? t('cap.health.title')}
          </p>
          <p className="mt-2 text-xs text-white/50">
            {t('cap.health.dataConfidence')}: {capacity.health?.data_confidence ?? '—'} · {t('cap.kpi.riskScore')}:{' '}
            {riskScore ?? '—'}
          </p>
        </div>
      ) : null}

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
        <div className="mb-4 flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-white">{t('overview.topHosts')}</h3>
          <Link to={`${basePath}/performance-analytics`} className="text-xs text-sky-400 hover:text-sky-300">
            {t('overview.viewPerformance')}
          </Link>
        </div>
        {topHosts.length === 0 ? (
          <p className="text-sm text-white/60">{t('overview.noUtilization')}</p>
        ) : (
          <ul className="space-y-2">
            {topHosts.map((host) => (
              <li
                key={host.name}
                className="flex items-center justify-between gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm"
              >
                <span className="font-medium">{host.name}</span>
                <span className="text-xs text-white/60">
                  {host.metric}: {typeof host.value === 'number' ? `${host.value}%` : host.value}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { observeService } from '../../services/observeService'
import {
  operatingSystemFromTags,
  summarizeHostServices,
  hostNamesMatch,
} from '../../lib/observeHostUtils'
import type {
  AlertHistoryEvent,
  CapacityTopRisk,
  ObserveServiceRow,
  PortScanResult,
} from '../../types/observe'

export interface HostDetailsHost {
  id?: number
  name: string
  address: string
  public_ip?: string | null
  tags?: string[]
  created_at?: string
  enabled?: boolean
  services?: Array<{ name: string; service_key?: string; enabled: boolean }>
}

interface HostDetailsDrawerProps {
  open: boolean
  onClose: () => void
  workspaceId: number
  host: HostDetailsHost | null
  serviceRows: ObserveServiceRow[]
  onConfigure?: () => void
  onAnalyzeHealth?: () => void
  showAiAnalyze?: boolean
}

function formatDateTime(dateString: string | null | undefined, locale: string): string {
  if (!dateString?.trim()) return '—'
  const date = new Date(dateString)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString(locale === 'ar' ? 'ar' : 'en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
}

function statusClass(status: string): string {
  if (status === 'ok' || status === 'up') return 'text-emerald-300'
  if (status === 'warning') return 'text-amber-300'
  if (status === 'critical' || status === 'unreachable') return 'text-rose-300'
  return 'text-white/60'
}

export function HostDetailsDrawer({
  open,
  onClose,
  workspaceId,
  host,
  serviceRows,
  onConfigure,
  onAnalyzeHealth,
  showAiAnalyze = false,
}: HostDetailsDrawerProps) {
  const { t, language } = useLanguage()
  const [alerts, setAlerts] = useState<AlertHistoryEvent[]>([])
  const [portScan, setPortScan] = useState<PortScanResult | null>(null)
  const [capacityRisk, setCapacityRisk] = useState<CapacityTopRisk | null>(null)
  const [loading, setLoading] = useState(false)

  const hostServices = useMemo(() => {
    if (!host) return []
    return serviceRows.filter((row) => hostNamesMatch(row.host, host.name, workspaceId))
  }, [host, serviceRows, workspaceId])

  const summary = useMemo(() => {
    if (!host) {
      return {
        status: 'unknown',
        lastCheck: '',
        serviceCheckCount: 0,
        healthy: 0,
        warning: 0,
        critical: 0,
        unknown: 0,
        pending: 0,
      }
    }
    return summarizeHostServices(serviceRows, host.name, workspaceId)
  }, [host, serviceRows, workspaceId])

  const osLabel = host ? operatingSystemFromTags(host.tags) : null

  const loadExtras = useCallback(async () => {
    if (!host?.name) return
    setLoading(true)
    try {
      const [history, scans, capacity] = await Promise.all([
        observeService.getAlertHistory(workspaceId, { target: host.name, limit: 20 }),
        host.id
          ? observeService.getHostPortScan(workspaceId, host.id)
          : observeService.getPortScans(workspaceId),
        observeService.getCapacityPlanning(workspaceId, '30d'),
      ])

      const hostAlerts = (Array.isArray(history) ? history : []).filter(
        (e) => e.host_name && hostNamesMatch(e.host_name, host.name, workspaceId),
      )
      setAlerts(hostAlerts.slice(0, 10))

      if (host.id) {
        setPortScan(scans as PortScanResult)
      } else {
        const list = Array.isArray(scans) ? scans : []
        const match = list.find(
          (p) => p.host_name === host.name || hostNamesMatch(p.host_name, host.name, workspaceId),
        )
        setPortScan(match ?? null)
      }

      const risks = capacity?.top_risks ?? capacity?.resource_analysis?.top_risks ?? []
      const shortName = host.name
      const risk = risks.find(
        (r) => r.host === shortName || r.host === host.name || hostNamesMatch(r.host, host.name, workspaceId),
      )
      setCapacityRisk(risk ?? null)
    } catch {
      setAlerts([])
      setPortScan(null)
      setCapacityRisk(null)
    } finally {
      setLoading(false)
    }
  }, [host, workspaceId])

  useEffect(() => {
    if (open && host) void loadExtras()
  }, [open, host, loadExtras])

  if (!open || !host) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/50">
      <button type="button" className="flex-1" onClick={onClose} aria-label={t('common.close')} />
      <div className="flex h-full w-full min-w-0 max-w-xl flex-col overflow-hidden border-l border-white/10 bg-[#0f151d] text-white shadow-xl">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-5 py-4">
          <h3 className="text-base font-semibold">{t('hosts.drawer.title')}</h3>
          <div className="flex flex-wrap items-center gap-2">
            {showAiAnalyze && onAnalyzeHealth ? (
              <button
                type="button"
                onClick={onAnalyzeHealth}
                className="rounded-lg border border-orange-500/40 bg-orange-500/15 px-3 py-1 text-xs text-orange-100 hover:bg-orange-500/25"
              >
                {t('hosts.drawer.analyzeHealth')}
              </button>
            ) : null}
            {onConfigure ? (
              <button
                type="button"
                onClick={onConfigure}
                className="rounded border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/10"
              >
                {t('hosts.drawer.configure')}
              </button>
            ) : null}
            <button
              type="button"
              onClick={onClose}
              className="rounded border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/10"
            >
              {t('common.close')}
            </button>
          </div>
        </div>

        <div className="min-w-0 flex-1 space-y-6 overflow-y-auto overflow-x-hidden p-5">
          <section>
            <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">
              {t('hosts.drawer.section.info')}
            </h4>
            <dl className="grid gap-2 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-white/50">{t('targets.hostName')}</dt>
                <dd className="text-end font-medium">{host.name || '—'}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-white/50">{t('targets.col.ip')}</dt>
                <dd className="text-end font-mono text-xs">{host.address || '—'}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-white/50">{t('hosts.col.os')}</dt>
                <dd className="text-end">{osLabel ?? t('hosts.osUnknown')}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-white/50">{t('targets.col.status')}</dt>
                <dd className={`text-end uppercase text-xs font-semibold ${statusClass(summary.status)}`}>
                  {summary.status}
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-white/50">{t('targets.col.lastCheck')}</dt>
                <dd className="text-end font-mono text-xs">{formatDateTime(summary.lastCheck, language)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-white/50">{t('hosts.drawer.monitoringSince')}</dt>
                <dd className="text-end font-mono text-xs">{formatDateTime(host.created_at, language)}</dd>
              </div>
            </dl>
          </section>

          <section>
            <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">
              {t('hosts.drawer.section.checks')}
            </h4>
            {hostServices.length === 0 ? (
              <p className="text-xs text-white/50">{t('targets.noServiceChecks')}</p>
            ) : (
              <ul className="space-y-2">
                {hostServices.map((svc) => (
                  <li
                    key={`${svc.host}-${svc.service}`}
                    className="flex items-center justify-between gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs"
                  >
                    <span className="min-w-0 truncate font-medium">{svc.service}</span>
                    <span className={`shrink-0 uppercase ${statusClass(svc.status)}`}>{svc.status}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section>
            <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">
              {t('hosts.drawer.section.alerts')}
            </h4>
            {loading ? (
              <p className="text-xs text-white/50">{t('common.loading')}</p>
            ) : alerts.length === 0 ? (
              <p className="text-xs text-white/50">{t('hosts.drawer.noAlerts')}</p>
            ) : (
              <ul className="space-y-2">
                {alerts.map((alert) => (
                  <li key={alert.id} className="rounded-lg border border-white/10 bg-white/5 p-3 text-xs">
                    <p className="font-medium">{alert.rule_name ?? alert.title}</p>
                    <p className="mt-1 text-white/50">
                      {formatDateTime(alert.triggered_at, language)} · {alert.status} · {alert.severity}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section>
            <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">
              {t('hosts.drawer.section.summary')}
            </h4>
            <div className="grid grid-cols-2 gap-2 text-xs">
              <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                <p className="text-white/50">{t('hosts.drawer.totalChecks')}</p>
                <p className="mt-1 text-lg font-semibold">{summary.serviceCheckCount}</p>
              </div>
              <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3">
                <p className="text-emerald-200/70">{t('hosts.drawer.healthyChecks')}</p>
                <p className="mt-1 text-lg font-semibold text-emerald-200">{summary.healthy}</p>
              </div>
              <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-3">
                <p className="text-amber-200/70">{t('hosts.drawer.warningChecks')}</p>
                <p className="mt-1 text-lg font-semibold text-amber-200">{summary.warning}</p>
              </div>
              <div className="rounded-lg border border-rose-500/20 bg-rose-500/10 p-3">
                <p className="text-rose-200/70">{t('hosts.drawer.criticalChecks')}</p>
                <p className="mt-1 text-lg font-semibold text-rose-200">{summary.critical}</p>
              </div>
            </div>
          </section>

          {portScan?.ports?.length ? (
            <section>
              <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">
                {t('hosts.drawer.section.ports')}
              </h4>
              <ul className="max-h-40 space-y-1 overflow-y-auto text-xs">
                {portScan.ports.map((p) => (
                  <li key={`${p.port}-${p.protocol}`} className="flex justify-between gap-2 text-white/70">
                    <span>
                      {p.port}/{p.protocol}
                    </span>
                    <span className="truncate text-white/50">{p.service ?? p.state}</span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          {capacityRisk ? (
            <section>
              <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">
                {t('hosts.drawer.section.capacity')}
              </h4>
              <div className="rounded-lg border border-white/10 bg-white/5 p-3 text-xs">
                <p>
                  {t('cap.risks.resource')}: {capacityRisk.resource}
                </p>
                <p className="mt-1">
                  {t('cap.utilization')}: {capacityRisk.utilization_pct != null ? `${capacityRisk.utilization_pct}%` : '—'}
                </p>
                <p className="mt-1">
                  {t('cap.risks.riskLevel')}: {capacityRisk.risk_level}
                </p>
              </div>
            </section>
          ) : null}
        </div>
      </div>
    </div>
  )
}

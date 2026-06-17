import { useCallback, useEffect, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { observeService } from '../../services/observeService'
import type { AlertHistoryEvent, MonitoringProfileCheck, ObserveServiceRow } from '../../types/observe'

interface ServiceDetailsDrawerProps {
  open: boolean
  onClose: () => void
  workspaceId: number
  service: ObserveServiceRow | null
  onRecheck?: () => void
  rechecking?: boolean
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

function inferServiceKey(serviceName: string): string | null {
  const n = serviceName.trim().toLowerCase()
  if (n.includes('cpu')) return 'cpu'
  if (n.includes('memory') || n.includes('ram')) return 'memory'
  if (n.includes('disk') || n.includes('storage')) return 'disk'
  if (n.includes('load')) return 'load'
  if (n.includes('ping') || n.includes('live')) return 'ping'
  return null
}

export function ServiceDetailsDrawer({
  open,
  onClose,
  workspaceId,
  service,
  onRecheck,
  rechecking = false,
}: ServiceDetailsDrawerProps) {
  const { t, language } = useLanguage()
  const [thresholds, setThresholds] = useState<MonitoringProfileCheck | null>(null)
  const [relatedAlerts, setRelatedAlerts] = useState<AlertHistoryEvent[]>([])
  const [loading, setLoading] = useState(false)

  const loadDetails = useCallback(async () => {
    if (!service) return
    setLoading(true)
    try {
      const [profile, history] = await Promise.all([
        observeService.getMonitoringProfile(workspaceId),
        observeService.getAlertHistory(workspaceId, {
          target: service.service,
          limit: 20,
        }),
      ])
      const key = inferServiceKey(service.service)
      const match = key ? (profile.checks ?? []).find((c) => c.service_key === key) : null
      setThresholds(match ?? null)
      const hostFiltered = (Array.isArray(history) ? history : []).filter(
        (e) =>
          e.host_name === service.host ||
          e.host_name?.endsWith(service.host.replace(/^ws\d+-/, '')) ||
          service.host.includes(e.host_name ?? ''),
      )
      setRelatedAlerts(hostFiltered)
    } catch {
      setThresholds(null)
      setRelatedAlerts([])
    } finally {
      setLoading(false)
    }
  }, [service, workspaceId])

  useEffect(() => {
    if (open && service) {
      void loadDetails()
    }
  }, [open, service, loadDetails])

  if (!open || !service) return null

  const stateHistory = service.lastStateChangeAt
    ? [
        {
          at: service.lastStateChangeAt,
          status: service.status,
          info: service.info,
        },
      ]
    : []

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/50">
      <button type="button" className="flex-1" onClick={onClose} aria-label={t('common.close')} />
      <div className="flex h-full w-full max-w-lg flex-col border-l border-white/10 bg-[#0f151d] text-white shadow-xl">
        <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
          <h3 className="text-base font-semibold">{t('services.drawer.title')}</h3>
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/10"
          >
            {t('common.close')}
          </button>
        </div>
        <div className="flex-1 space-y-5 overflow-y-auto p-5">
          <div className="space-y-2 text-sm">
            <div>
              <span className="text-xs text-white/50">{t('services.drawer.serviceName')}</span>
              <p className="font-medium">{service.service}</p>
            </div>
            <div>
              <span className="text-xs text-white/50">{t('services.drawer.host')}</span>
              <p>{service.host}</p>
            </div>
            <div>
              <span className="text-xs text-white/50">{t('services.drawer.status')}</span>
              <p className="uppercase">{service.status}</p>
            </div>
            <div>
              <span className="text-xs text-white/50">{t('services.drawer.lastCheck')}</span>
              <p className="font-mono tabular-nums">{formatDateTime(service.lastCheckAt, language)}</p>
            </div>
          </div>

          <div>
            <h4 className="mb-2 text-xs font-semibold uppercase text-white/60">{t('services.drawer.thresholds')}</h4>
            {loading ? (
              <p className="text-xs text-white/50">{t('common.loading')}</p>
            ) : thresholds && thresholds.service_key !== 'ping' ? (
              <div className="rounded-lg border border-white/10 bg-white/5 p-3 text-xs">
                <p>
                  {t('thresholds.warn')}: {String(thresholds.check_args.warn_pct ?? '—')}%
                </p>
                <p>
                  {t('thresholds.crit')}: {String(thresholds.check_args.crit_pct ?? '—')}%
                </p>
              </div>
            ) : (
              <p className="text-xs text-white/50">{t('services.drawer.noThresholds')}</p>
            )}
          </div>

          <div>
            <h4 className="mb-2 text-xs font-semibold uppercase text-white/60">{t('services.drawer.stateHistory')}</h4>
            {stateHistory.length === 0 ? (
              <p className="text-xs text-white/50">{t('services.drawer.noStateHistory')}</p>
            ) : (
              <ul className="space-y-2">
                {stateHistory.map((entry) => (
                  <li key={entry.at} className="rounded-lg border border-white/10 bg-white/5 p-3 text-xs">
                    <p className="font-mono text-white/70">{formatDateTime(entry.at, language)}</p>
                    <p className="mt-1 uppercase">{entry.status}</p>
                    {entry.info ? <p className="mt-1 text-white/60">{entry.info}</p> : null}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            <h4 className="mb-2 text-xs font-semibold uppercase text-white/60">{t('services.drawer.relatedAlerts')}</h4>
            {loading ? (
              <p className="text-xs text-white/50">{t('common.loading')}</p>
            ) : relatedAlerts.length === 0 ? (
              <p className="text-xs text-white/50">{t('services.drawer.noAlerts')}</p>
            ) : (
              <ul className="space-y-2">
                {relatedAlerts.map((alert) => (
                  <li key={alert.id} className="rounded-lg border border-white/10 bg-white/5 p-3 text-xs">
                    <p className="font-medium">{alert.rule_name ?? alert.message}</p>
                    <p className="text-white/60">{formatDateTime(alert.triggered_at, language)} · {alert.status}</p>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
        <div className="flex gap-2 border-t border-white/10 p-4">
          <button
            type="button"
            onClick={onClose}
            className="flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white hover:bg-white/10"
          >
            {t('services.action.view')}
          </button>
          {onRecheck ? (
            <button
              type="button"
              onClick={onRecheck}
              disabled={rechecking}
              className="flex-1 rounded-lg border border-sky-500/30 bg-sky-500/20 px-3 py-2 text-xs text-sky-200 hover:bg-sky-500/30 disabled:opacity-50"
            >
              {rechecking ? t('services.rechecking') : t('services.action.recheck')}
            </button>
          ) : null}
        </div>
      </div>
    </div>
  )
}

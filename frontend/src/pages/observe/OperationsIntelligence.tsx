import { useState } from 'react'
import type { ReactNode } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatCard } from '../../components/observe/StatCard'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { operationsIntelligenceService } from '../../services/operationsIntelligenceService'
import { OperationsCopilotDrawer } from '../../components/observe/intelligence/OperationsCopilotDrawer'
import { OperationsAlertDrawer } from '../../components/observe/intelligence/OperationsAlertDrawer'
import type { OpsAlertSummary } from '../../types/operationsIntelligence'

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'text-rose-300',
  warning: 'text-amber-300',
  info: 'text-sky-300',
  healthy: 'text-emerald-300',
}

export default function OperationsIntelligence() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error } = useAiResource((ws) => operationsIntelligenceService.getOverview(ws), [])

  const [copilotOpen, setCopilotOpen] = useState(false)
  const [alert, setAlert] = useState<OpsAlertSummary | null>(null)

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('opsIntel.title')} subtitle={t('opsIntel.subtitle')} />
        <EmptyState title={t('opsIntel.noWorkspace.title')} description={t('opsIntel.noWorkspace.description')} />
      </div>
    )
  }

  const health = data?.infrastructure_health

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('opsIntel.title')}
        subtitle={t('opsIntel.subtitle')}
        actions={
          <button
            onClick={() => setCopilotOpen(true)}
            className="inline-flex items-center gap-1.5 rounded-full border border-amber-400/40 bg-amber-400/10 px-4 py-2 text-sm font-semibold text-amber-100 hover:bg-amber-400/20"
          >
            <span aria-hidden>✨</span>
            {t('opsIntel.askCopilot')}
          </button>
        }
      />

      {loading ? <p className="text-sm text-white/50">{t('opsIntel.loading')}</p> : null}
      {error ? <p className="text-sm text-rose-300">{error}</p> : null}

      {data && !loading ? (
        <>
          {/* Health summary */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard title={t('opsIntel.stat.hosts')} value={String(health?.hosts_total ?? 0)} detail={`${health?.hosts_enabled ?? 0} ${t('opsIntel.stat.enabled')}`} />
            <StatCard title={t('opsIntel.stat.criticalServices')} value={String(health?.service_state_counts.critical ?? 0)} detail={`${health?.services_total ?? 0} ${t('opsIntel.stat.total')}`} />
            <StatCard title={t('opsIntel.stat.openAlerts')} value={String(data.open_alert_count)} />
            <StatCard title={t('opsIntel.stat.unhealthyHosts')} value={String(health?.unhealthy_host_count ?? 0)} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {/* Top operational risks */}
            <Panel title={t('opsIntel.section.topRisks')}>
              {data.top_operational_risks.length === 0 ? (
                <Muted>{t('opsIntel.empty.risks')}</Muted>
              ) : (
                <ul className="space-y-2">
                  {data.top_operational_risks.map((r, i) => (
                    <li key={i} className="flex items-start gap-2 text-sm">
                      <span className={`mt-0.5 text-xs font-semibold uppercase ${SEVERITY_CLASS[r.severity] ?? 'text-white/60'}`}>{r.severity}</span>
                      <span className="text-white/80">{r.summary}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Predicted capacity risks */}
            <Panel title={t('opsIntel.section.capacityRisks')}>
              {data.predicted_capacity_risks.filter((r) => r.status !== 'healthy').length === 0 ? (
                <Muted>{t('opsIntel.empty.capacity')}</Muted>
              ) : (
                <ul className="space-y-2">
                  {data.predicted_capacity_risks.map((r) => (
                    <li key={r.resource} className="flex items-center justify-between text-sm">
                      <span className="capitalize text-white/80">{r.resource}</span>
                      <span className={SEVERITY_CLASS[r.status] ?? 'text-white/60'}>
                        {r.days !== null ? `${r.days} ${t('opsIntel.capacity.days')}` : r.status}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Open alerts with explain action */}
            <Panel title={t('opsIntel.section.alerts')}>
              {data.open_alerts.length === 0 ? (
                <Muted>{t('opsIntel.empty.alerts')}</Muted>
              ) : (
                <ul className="space-y-2">
                  {data.open_alerts.map((a) => (
                    <li key={a.uuid} className="flex items-center justify-between gap-2 text-sm">
                      <span className="min-w-0 truncate">
                        <span className={`mr-2 text-xs font-semibold uppercase ${SEVERITY_CLASS[a.severity] ?? 'text-white/60'}`}>{a.severity}</span>
                        <span className="text-white/80">{a.title}</span>
                        {a.host ? <span className="text-white/40"> · {a.host}</span> : null}
                      </span>
                      <button
                        onClick={() => setAlert(a)}
                        className="shrink-0 rounded-full border border-amber-400/40 bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-100 hover:bg-amber-400/20"
                      >
                        ✨ {t('ai.action.explain')}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Critical services */}
            <Panel title={t('opsIntel.section.criticalServices')}>
              {data.critical_services.length === 0 ? (
                <Muted>{t('opsIntel.empty.criticalServices')}</Muted>
              ) : (
                <ul className="space-y-2">
                  {data.critical_services.map((s) => (
                    <li key={s.uuid} className="flex items-center justify-between text-sm">
                      <span className="text-white/80">{s.name} <span className="text-white/40">· {s.host}</span></span>
                      <span className={SEVERITY_CLASS[s.state] ?? 'text-white/60'}>{s.state}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Recommendations */}
            <Panel title={t('opsIntel.section.recommendations')}>
              {data.recent_recommendations.length === 0 ? (
                <Muted>{t('opsIntel.empty.recommendations')}</Muted>
              ) : (
                <ul className="space-y-3">
                  {data.recent_recommendations.map((rec, i) => (
                    <li key={i} className="text-sm">
                      <div className="flex items-center gap-2">
                        <span className={`text-xs font-semibold uppercase ${SEVERITY_CLASS[rec.severity] ?? 'text-white/60'}`}>{rec.severity}</span>
                        <span className="font-medium text-white/90">{rec.title}</span>
                      </div>
                      <p className="mt-0.5 text-xs text-white/60">{rec.rationale}</p>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Recent AI investigations */}
            <Panel title={t('opsIntel.section.investigations')}>
              {data.recent_ai_investigations.length === 0 ? (
                <Muted>{t('opsIntel.empty.investigations')}</Muted>
              ) : (
                <ul className="space-y-2">
                  {data.recent_ai_investigations.map((inv, i) => (
                    <li key={i} className="flex items-center justify-between text-sm">
                      <span className="text-white/80">{inv.action.replace(/_/g, ' ')}</span>
                      <span className="text-xs text-white/40">{inv.at ? new Date(inv.at).toLocaleString() : ''}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>
        </>
      ) : null}

      <OperationsCopilotDrawer open={copilotOpen} onClose={() => setCopilotOpen(false)} workspaceUuid={workspaceUuid} />
      <OperationsAlertDrawer open={alert !== null} onClose={() => setAlert(null)} workspaceUuid={workspaceUuid} alert={alert} />
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
      <h2 className="mb-3 text-sm font-semibold text-white">{title}</h2>
      {children}
    </div>
  )
}

function Muted({ children }: { children: ReactNode }) {
  return <p className="text-xs text-white/40">{children}</p>
}

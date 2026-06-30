import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { qynvaService } from '../../services/qynvaService'
import type { EnterpriseAnalytics as Analytics, MetricBlock } from '../../types/qynva'
import { QynvaTabs } from './QynvaTabs'

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
      <p className="text-xs uppercase tracking-wide text-white/40">{label}</p>
      <p className="mt-1 text-xl font-semibold text-white">{value}</p>
    </div>
  )
}

function metricValue(m: MetricBlock, unavailable: string): string {
  return m.available ? (m.human ?? String(m.avg_seconds ?? '—')) : unavailable
}

/**
 * Sprint 25 — Enterprise Analytics. MTTD/MTTR, incident trends, automation effectiveness, AI adoption,
 * knowledge usage, asset growth, capacity trends, notification statistics, and executive KPIs — all from
 * real rows, honest about unavailable data.
 */
export default function EnterpriseAnalytics() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [data, setData] = useState<Analytics | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    try {
      setData(await qynvaService.analytics(workspaceUuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load analytics')
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('qynva.analyticsTitle')} subtitle={t('qynva.analyticsSubtitle')} />
        <EmptyState title={t('qynva.noWorkspace.title')} description={t('qynva.noWorkspace.description')} />
      </div>
    )
  }

  const na = t('qynva.notAvailable')

  return (
    <div className="space-y-6">
      <PageHeader title={t('qynva.analyticsTitle')} subtitle={t('qynva.analyticsSubtitle')} />
      <QynvaTabs />
      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Metric label={t('qynva.mttd')} value={metricValue(data.mttd, na)} />
            <Metric label={t('qynva.mttrIncident')} value={metricValue(data.mttr.incident, na)} />
            <Metric label={t('qynva.mttrAlert')} value={metricValue(data.mttr.alert, na)} />
            <Metric label={t('qynva.aiConversations')} value={String((data.ai_adoption as Record<string, unknown>).conversations ?? na)} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
              <p className="mb-2 text-sm font-semibold text-white">{t('qynva.automationEffectiveness')}</p>
              <pre className="overflow-auto text-xs text-white/60">{JSON.stringify(data.automation_effectiveness, null, 2)}</pre>
            </div>
            <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
              <p className="mb-2 text-sm font-semibold text-white">{t('qynva.notificationStatistics')}</p>
              <pre className="overflow-auto text-xs text-white/60">{JSON.stringify(data.notification_statistics, null, 2)}</pre>
            </div>
            <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
              <p className="mb-2 text-sm font-semibold text-white">{t('qynva.assetGrowth')}</p>
              <pre className="overflow-auto text-xs text-white/60">{JSON.stringify(data.asset_growth, null, 2)}</pre>
            </div>
            <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
              <p className="mb-2 text-sm font-semibold text-white">{t('qynva.incidentTrends')}</p>
              <pre className="overflow-auto text-xs text-white/60">{JSON.stringify(data.incident_trends, null, 2)}</pre>
            </div>
          </div>
        </>
      ) : (
        <p className="text-sm text-white/40">{t('qynva.loading')}</p>
      )}
    </div>
  )
}

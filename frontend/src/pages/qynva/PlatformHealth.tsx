import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { qynvaService } from '../../services/qynvaService'
import type { PlatformHealthSnapshot } from '../../types/qynva'
import { QynvaTabs } from './QynvaTabs'

function statusColor(status: string): string {
  return status === 'operational' ? 'text-emerald-300' : status === 'degraded' ? 'text-amber-300' : 'text-rose-300'
}

/**
 * Sprint 25 — Platform Health (the platform monitoring itself): AI/automation/knowledge platforms,
 * search, registries, providers, queues, event bus, background jobs. Privileged view.
 */
export default function PlatformHealth() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [data, setData] = useState<PlatformHealthSnapshot | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    try {
      setData(await qynvaService.health(workspaceUuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load platform health')
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('qynva.healthTitle')} subtitle={t('qynva.healthSubtitle')} />
        <EmptyState title={t('qynva.noWorkspace.title')} description={t('qynva.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('qynva.healthTitle')} subtitle={t('qynva.healthSubtitle')} />
      <QynvaTabs />
      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      {data ? (
        <>
          <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
            <p className="text-xs uppercase tracking-wide text-white/40">{t('qynva.overallStatus')}</p>
            <p className={`mt-1 text-lg font-semibold ${statusColor(data.overall_status)}`}>{data.overall_status}</p>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {Object.entries(data.areas).map(([key, area]) => (
              <div key={key} className="rounded-lg border border-white/10 bg-[#0f151d] p-3">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-white">{key.replace(/_/g, ' ')}</span>
                  <span className={`text-xs font-semibold ${statusColor(area.status)}`}>{area.status}</span>
                </div>
                <pre className="mt-2 overflow-auto text-[11px] text-white/50">{JSON.stringify(area, null, 2)}</pre>
              </div>
            ))}
          </div>
        </>
      ) : (
        <p className="text-sm text-white/40">{t('qynva.loading')}</p>
      )}
    </div>
  )
}

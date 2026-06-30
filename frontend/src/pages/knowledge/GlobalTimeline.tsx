import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { knowledgeService } from '../../services/knowledgeService'
import type { TimelineEvent } from '../../types/knowledge'

const MODULE_CLASS: Record<string, string> = {
  qynreact: 'text-rose-300',
  qynrun: 'text-emerald-300',
  qynsupport: 'text-amber-300',
  qynnotify: 'text-fuchsia-300',
  qynknow: 'text-sky-300',
}

export default function GlobalTimeline() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()

  const [events, setEvents] = useState<TimelineEvent[]>([])
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    setLoading(true)
    try {
      setEvents((await knowledgeService.timeline(workspaceUuid)).events)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load timeline')
    } finally {
      setLoading(false)
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('timeline.title')} subtitle={t('timeline.subtitle')} />
        <EmptyState title={t('timeline.noWorkspace.title')} description={t('timeline.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('timeline.title')} subtitle={t('timeline.subtitle')} />

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}
      {loading ? <p className="text-xs text-white/40">{t('timeline.loading')}</p> : null}
      {!loading && events.length === 0 ? <EmptyState title={t('timeline.empty.title')} description={t('timeline.empty.description')} /> : null}

      <div className="space-y-2">
        {events.map((e, idx) => (
          <div key={idx} className="flex gap-3 border-l border-white/10 pl-4">
            <div className="min-w-[150px] text-[11px] text-white/40">{e.at}</div>
            <div>
              <div className="text-sm text-white/85">{e.title}</div>
              <div className="text-xs text-white/50">
                <span className={MODULE_CLASS[e.module] ?? 'text-white/50'}>{e.module}</span> · {e.type}{e.description ? ` · ${e.description}` : ''}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

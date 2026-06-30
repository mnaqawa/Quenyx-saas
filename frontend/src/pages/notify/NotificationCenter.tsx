import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { notifyService } from '../../services/notifyService'
import type { CorrelationGroup, NotificationSummary } from '../../types/notify'
import type { AiNarrative } from '../../types/automation'

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'text-rose-300',
  high: 'text-amber-300',
  medium: 'text-sky-300',
  low: 'text-white/50',
  info: 'text-white/40',
}

export default function NotificationCenter() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()

  const [notifications, setNotifications] = useState<NotificationSummary[]>([])
  const [correlations, setCorrelations] = useState<CorrelationGroup[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [ai, setAi] = useState<{ kind: string; narrative: AiNarrative } | null>(null)
  const [copilotOpen, setCopilotOpen] = useState(false)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    setLoading(true)
    try {
      const res = await notifyService.list(workspaceUuid)
      setNotifications(res.notifications)
      setCorrelations(res.correlations)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load notifications')
    } finally {
      setLoading(false)
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  const markRead = useCallback(async (uuid: string) => {
    if (!workspaceUuid) return
    try {
      await notifyService.markRead(workspaceUuid, uuid)
      await load()
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to update')
    }
  }, [workspaceUuid, load])

  const runAi = useCallback(async (kind: 'digest' | 'executive') => {
    if (!workspaceUuid) return
    setBusy(true)
    setAi(null)
    try {
      if (kind === 'digest') {
        setAi({ kind, narrative: (await notifyService.digest(workspaceUuid)).digest })
      } else {
        setAi({ kind, narrative: (await notifyService.executive(workspaceUuid)).executive_summary })
      }
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'AI action failed')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('notify.title')} subtitle={t('notify.subtitle')} />
        <EmptyState title={t('notify.noWorkspace.title')} description={t('notify.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <PageHeader title={t('notify.title')} subtitle={t('notify.subtitle')} />
        <div className="flex gap-2">
          <button onClick={() => setCopilotOpen(true)} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20">✨ {t('notify.copilot')}</button>
          <button disabled={busy} onClick={() => runAi('digest')} className="rounded-full border border-sky-400/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-400/10">{t('notify.digest')}</button>
          <button disabled={busy} onClick={() => runAi('executive')} className="rounded-full border border-white/15 px-3 py-1.5 text-xs text-white/70 hover:bg-white/10">{t('notify.executive')}</button>
        </div>
      </div>

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      {ai ? (
        <div className="rounded-lg border border-white/10 bg-[#0b0f14] p-3">
          <p className="text-[10px] uppercase tracking-wide text-white/40">{ai.kind}</p>
          <p className="mt-1 whitespace-pre-wrap text-sm text-white/85">{ai.narrative.content ?? ai.narrative.error ?? t('notify.noAnswer')}</p>
          {ai.narrative.ai_enabled === false ? <p className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{t('aiCopilot.mockNotice')}</p> : null}
        </div>
      ) : null}

      {correlations.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {correlations.map((c) => (
            <span key={c.correlation_id} className="rounded-full border border-white/10 bg-[#0f151d] px-2 py-1 text-[10px] text-white/60">
              {t('notify.cluster')} {c.correlation_id.slice(0, 8)} · {c.count} · ⚡{c.max_urgency}
            </span>
          ))}
        </div>
      ) : null}

      {loading ? <p className="text-xs text-white/40">{t('notify.loading')}</p> : null}
      {!loading && notifications.length === 0 ? <EmptyState title={t('notify.empty.title')} description={t('notify.empty.description')} /> : null}

      <div className="space-y-2">
        {notifications.map((n) => (
          <div key={n.uuid} className="flex items-start justify-between rounded-lg border border-white/10 bg-[#0f151d] p-3">
            <div>
              <div className="flex items-center gap-2">
                <span className={`text-[10px] uppercase ${SEVERITY_CLASS[n.severity] ?? 'text-white/50'}`}>{n.severity}</span>
                <span className="text-sm font-medium text-white">{n.title}</span>
                {n.dedup_count > 1 ? <span className="rounded-full bg-white/10 px-1.5 text-[10px] text-white/60">×{n.dedup_count}</span> : null}
              </div>
              <div className="text-xs text-white/40">{n.source} · {n.channel ?? '—'} · ⚡{n.urgency_score} · {n.recipients.length} {t('notify.recipients')}</div>
            </div>
            {n.status !== 'read' ? (
              <button onClick={() => markRead(n.uuid)} className="rounded-full border border-white/15 px-2 py-1 text-[10px] text-white/60 hover:bg-white/10">{t('notify.markRead')}</button>
            ) : <span className="text-[10px] text-white/30">{t('notify.read')}</span>}
          </div>
        ))}
      </div>

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => notifyService.copilot(w, message, conversation)}
        title={t('notify.copilotTitle')}
        introText={t('notify.copilotIntro')}
      />
    </div>
  )
}

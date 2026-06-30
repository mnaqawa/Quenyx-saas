import { useCallback, useEffect, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { collaborationService } from '../../services/collaborationService'
import type { CollabEntityType, CollabThread } from '../../types/collaboration'

interface CollaborationPanelProps {
  workspaceUuid: string
  entityType: CollabEntityType
  entityUuid: string
}

/**
 * Sprint 24 — reusable Collaboration panel (comments + participants) for ANY entity. Backed by the
 * shared Collaboration Platform, so every module embeds the same component — no per-module chat.
 */
export function CollaborationPanel({ workspaceUuid, entityType, entityUuid }: CollaborationPanelProps) {
  const { t } = useLanguage()
  const [thread, setThread] = useState<CollabThread | null>(null)
  const [input, setInput] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!workspaceUuid || !entityUuid) return
    try {
      setThread(await collaborationService.thread(workspaceUuid, entityType, entityUuid))
    } catch {
      setThread(null)
    }
  }, [workspaceUuid, entityType, entityUuid])

  useEffect(() => {
    void load()
  }, [load])

  const submit = useCallback(async () => {
    if (!input.trim()) return
    setBusy(true)
    try {
      const res = await collaborationService.comment(workspaceUuid, entityType, entityUuid, input.trim())
      setThread(res.thread)
      setInput('')
    } finally {
      setBusy(false)
    }
  }, [input, workspaceUuid, entityType, entityUuid])

  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold text-white">{t('collab.title')}</h3>
      {thread && thread.participants.length > 0 ? (
        <div className="flex flex-wrap gap-1">
          {thread.participants.map((p, i) => (
            <span key={i} className="rounded-full border border-white/10 bg-[#0f151d] px-2 py-0.5 text-[10px] text-white/60">
              {p.user?.name ?? '—'} · {p.role}
            </span>
          ))}
        </div>
      ) : null}
      <div className="space-y-2">
        {!thread || thread.comments.length === 0 ? (
          <p className="text-xs text-white/40">{t('collab.empty')}</p>
        ) : (
          thread.comments.map((c) => (
            <div key={c.uuid} className="rounded-lg border border-white/10 bg-[#0f151d] p-2 text-xs">
              <div className="text-white/50">{c.author?.name ?? '—'} · {c.created_at ?? ''}</div>
              <div className="mt-1 whitespace-pre-wrap text-white/85">{c.body}</div>
            </div>
          ))
        )}
      </div>
      <div className="flex gap-2">
        <input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder={t('collab.placeholder')}
          className="flex-1 rounded-lg border border-white/15 bg-[#0f151d] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none"
        />
        <button disabled={busy || !input.trim()} onClick={submit} className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">
          {t('collab.send')}
        </button>
      </div>
    </section>
  )
}

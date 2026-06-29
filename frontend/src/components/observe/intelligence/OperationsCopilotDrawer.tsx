import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useLanguage } from '../../../i18n/LanguageContext'
import { operationsIntelligenceService } from '../../../services/operationsIntelligenceService'
import type { OpsAiNarrative } from '../../../types/operationsIntelligence'

interface Turn {
  role: 'user' | 'assistant'
  text: string
  mocked?: boolean
  aiEnabled?: boolean
}

interface OperationsCopilotDrawerProps {
  open: boolean
  onClose: () => void
  workspaceUuid: string | null
  /** Optional question to ask automatically when the drawer opens. */
  seedQuestion?: string | null
  title?: string
}

/**
 * Sprint 21 — Monitoring Copilot drawer. Runs the grounded Operations Copilot and REUSES the shared
 * Quenyx AI conversation surface: each thread is a real conversation that can be opened in Quenyx AI.
 * Answers are grounded in current QynSight evidence; when AI is disabled the mock provider answers
 * (clearly flagged) while the underlying operational data stays real.
 */
export function OperationsCopilotDrawer({ open, onClose, workspaceUuid, seedQuestion, title }: OperationsCopilotDrawerProps) {
  const { t } = useLanguage()
  const [turns, setTurns] = useState<Turn[]>([])
  const [input, setInput] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [conversationUuid, setConversationUuid] = useState<string | null>(null)
  const seededRef = useRef<string | null>(null)

  const ask = useCallback(
    async (question: string) => {
      if (!workspaceUuid || !question.trim()) return
      setLoading(true)
      setError(null)
      setTurns((prev) => [...prev, { role: 'user', text: question }])
      try {
        const res = await operationsIntelligenceService.copilot(workspaceUuid, question, conversationUuid ?? undefined)
        setConversationUuid(res.conversation_uuid)
        const answer: OpsAiNarrative = res.answer
        setTurns((prev) => [
          ...prev,
          {
            role: 'assistant',
            text: answer.content ?? (answer.error ? `${t('opsIntel.copilot.error')}: ${answer.error}` : t('opsIntel.copilot.noAnswer')),
            mocked: answer.mocked,
            aiEnabled: answer.ai_enabled,
          },
        ])
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : t('opsIntel.copilot.error'))
      } finally {
        setLoading(false)
      }
    },
    [workspaceUuid, conversationUuid, t]
  )

  // Reset + auto-seed when (re)opened.
  useEffect(() => {
    if (!open) {
      seededRef.current = null
      return
    }
    if (seedQuestion && seededRef.current !== seedQuestion) {
      seededRef.current = seedQuestion
      setTurns([])
      setConversationUuid(null)
      void ask(seedQuestion)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, seedQuestion])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-black/60" onClick={onClose} />
      <div
        data-drawer-panel
        className="relative flex h-full w-full max-w-md flex-col border-l border-white/10 bg-[#0b0f14] shadow-2xl"
      >
        <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
          <div className="flex items-center gap-2">
            <span className="text-amber-300">✨</span>
            <h2 className="text-sm font-semibold text-white">{title ?? t('opsIntel.copilot.title')}</h2>
          </div>
          <button onClick={onClose} className="rounded-full p-1 text-white/50 hover:bg-white/10 hover:text-white" aria-label={t('common.close')}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="flex-1 space-y-3 overflow-y-auto px-5 py-4">
          {turns.length === 0 && !loading ? (
            <p className="text-xs text-white/50">{t('opsIntel.copilot.intro')}</p>
          ) : null}
          {turns.map((turn, idx) => (
            <div key={idx} className={turn.role === 'user' ? 'text-right' : 'text-left'}>
              <div
                className={`inline-block max-w-[90%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm ${
                  turn.role === 'user' ? 'bg-sky-600 text-white' : 'border border-white/10 bg-[#0f151d] text-white/90'
                }`}
              >
                {turn.text}
                {turn.role === 'assistant' && turn.aiEnabled === false ? (
                  <div className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{t('opsIntel.copilot.mockNotice')}</div>
                ) : null}
              </div>
            </div>
          ))}
          {loading ? <p className="text-xs text-white/50">{t('opsIntel.copilot.thinking')}</p> : null}
          {error ? <p className="text-xs text-rose-300">{error}</p> : null}
        </div>

        {conversationUuid ? (
          <div className="border-t border-white/10 px-5 py-2">
            <Link to={`/ai-workspace/conversations/${conversationUuid}`} className="text-xs text-sky-300 hover:underline">
              {t('opsIntel.openInAi')}
            </Link>
          </div>
        ) : null}

        <form
          className="flex items-center gap-2 border-t border-white/10 px-5 py-3"
          onSubmit={(e) => {
            e.preventDefault()
            const q = input
            setInput('')
            void ask(q)
          }}
        >
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            placeholder={t('opsIntel.copilot.placeholder')}
            disabled={loading || !workspaceUuid}
            className="flex-1 rounded-full border border-white/15 bg-[#0f151d] px-4 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none"
          />
          <button
            type="submit"
            disabled={loading || !workspaceUuid || !input.trim()}
            className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
          >
            {t('opsIntel.copilot.send')}
          </button>
        </form>
      </div>
    </div>
  )
}

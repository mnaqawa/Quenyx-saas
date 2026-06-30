import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'

interface Turn {
  role: 'user' | 'assistant'
  text: string
  mocked?: boolean
  aiEnabled?: boolean
}

interface CopilotAnswer {
  content?: string
  error?: string
  mocked?: boolean
  ai_enabled?: boolean
}

interface CopilotResult {
  conversation_uuid: string
  answer: CopilotAnswer
}

interface AiCopilotDrawerProps {
  open: boolean
  onClose: () => void
  workspaceUuid: string | null
  /** Module copilot function (reuses the shared Quenyx AI conversation surface on the backend). */
  copilot: (workspaceUuid: string, message: string, conversation?: string) => Promise<CopilotResult>
  /** Optional question to ask automatically when the drawer opens. */
  seedQuestion?: string | null
  title?: string
  introText?: string
  placeholder?: string
}

/**
 * Sprint 22 — generic, reusable AI Copilot drawer for the AI Adapter Platform.
 *
 * Module-agnostic: it takes a module `copilot` function (QynSight, QynAsset, future modules) and runs
 * a grounded conversation that REUSES the shared Quenyx AI conversation surface — each thread is a
 * real conversation openable in Quenyx AI. When AI is disabled the mock provider answers (flagged)
 * while the underlying evidence stays real. There is no duplicated chat system.
 */
export function AiCopilotDrawer({ open, onClose, workspaceUuid, copilot, seedQuestion, title, introText, placeholder }: AiCopilotDrawerProps) {
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
        const res = await copilot(workspaceUuid, question, conversationUuid ?? undefined)
        setConversationUuid(res.conversation_uuid)
        const answer = res.answer
        setTurns((prev) => [
          ...prev,
          {
            role: 'assistant',
            text: answer.content ?? (answer.error ? `${t('aiCopilot.error')}: ${answer.error}` : t('aiCopilot.noAnswer')),
            mocked: answer.mocked,
            aiEnabled: answer.ai_enabled,
          },
        ])
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : t('aiCopilot.error'))
      } finally {
        setLoading(false)
      }
    },
    [workspaceUuid, conversationUuid, copilot, t]
  )

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
      <div data-drawer-panel className="relative flex h-full w-full max-w-md flex-col border-l border-white/10 bg-[#0b0f14] shadow-2xl">
        <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
          <div className="flex items-center gap-2">
            <span className="text-amber-300">✨</span>
            <h2 className="text-sm font-semibold text-white">{title ?? t('aiCopilot.title')}</h2>
          </div>
          <button onClick={onClose} className="rounded-full p-1 text-white/50 hover:bg-white/10 hover:text-white" aria-label={t('common.close')}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="flex-1 space-y-3 overflow-y-auto px-5 py-4">
          {turns.length === 0 && !loading ? <p className="text-xs text-white/50">{introText ?? t('aiCopilot.intro')}</p> : null}
          {turns.map((turn, idx) => (
            <div key={idx} className={turn.role === 'user' ? 'text-right' : 'text-left'}>
              <div
                className={`inline-block max-w-[90%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm ${
                  turn.role === 'user' ? 'bg-sky-600 text-white' : 'border border-white/10 bg-[#0f151d] text-white/90'
                }`}
              >
                {turn.text}
                {turn.role === 'assistant' && turn.aiEnabled === false ? (
                  <div className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{t('aiCopilot.mockNotice')}</div>
                ) : null}
              </div>
            </div>
          ))}
          {loading ? <p className="text-xs text-white/50">{t('aiCopilot.thinking')}</p> : null}
          {error ? <p className="text-xs text-rose-300">{error}</p> : null}
        </div>

        {conversationUuid ? (
          <div className="border-t border-white/10 px-5 py-2">
            <Link to={`/ai-workspace/conversations/${conversationUuid}`} className="text-xs text-sky-300 hover:underline">
              {t('aiCopilot.openInAi')}
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
            placeholder={placeholder ?? t('aiCopilot.placeholder')}
            disabled={loading || !workspaceUuid}
            className="flex-1 rounded-full border border-white/15 bg-[#0f151d] px-4 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none"
          />
          <button
            type="submit"
            disabled={loading || !workspaceUuid || !input.trim()}
            className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
          >
            {t('aiCopilot.send')}
          </button>
        </form>
      </div>
    </div>
  )
}

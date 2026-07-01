import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiChatTypingIndicator } from '../../components/ai/workspace/AiChatTypingIndicator'
import { AiError, Card, NoWorkspaceNotice } from '../../components/ai/workspace/shared'
import { formatDateTime } from '../../components/ai/workspace/format'
import type { AiConversation, AiConversationMessage, AiRuntimeMode } from '../../types/aiWorkspace'

function conversationLabel(c: AiConversation): string {
  const title = c.title?.trim()
  if (title) return title
  return `${c.uuid.slice(0, 8)}…`
}

/**
 * AI Chat — workspace conversations with persistence, resume, and visible working state.
 */
export default function AiChat() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const { uuid: routeUuid } = useParams<{ uuid?: string }>()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const summary = useAiResource((uuid) => aiWorkspaceService.getSummary(uuid))
  const recent = useAiResource((uuid) => aiWorkspaceService.listConversations(uuid))

  const [conversationUuid, setConversationUuid] = useState<string | null>(routeUuid ?? null)
  const [messages, setMessages] = useState<AiConversationMessage[]>([])
  const [loadingConversation, setLoadingConversation] = useState(Boolean(routeUuid))
  const [input, setInput] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [runtimeMode, setRuntimeMode] = useState<AiRuntimeMode | null>(null)

  const scrollRef = useRef<HTMLDivElement>(null)
  const endRef = useRef<HTMLDivElement>(null)

  const knowledgeActive = summary.data?.summary.knowledge_base_enabled ?? false

  const scrollToBottom = useCallback(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [])

  useEffect(() => {
    scrollToBottom()
  }, [messages, sending, scrollToBottom])

  // Load conversation when opening /chat/:uuid
  useEffect(() => {
    if (!workspaceUuid || !routeUuid) {
      if (!routeUuid) {
        setConversationUuid(null)
        setMessages([])
        setLoadingConversation(false)
      }
      return
    }

    let cancelled = false
    setLoadingConversation(true)
    setError(null)
    setConversationUuid(routeUuid)

    aiWorkspaceService
      .getConversation(workspaceUuid, routeUuid)
      .then((conv) => {
        if (cancelled) return
        setMessages(
          (conv.messages ?? []).filter(
            (m) => m.content !== null && m.content !== '' && (m.role === 'user' || m.role === 'assistant')
          )
        )
      })
      .catch((err: unknown) => {
        if (cancelled) return
        const msg = err instanceof Error ? err.message : t('aiWorkspace.chat.loadError')
        setError(msg)
        setMessages([])
      })
      .finally(() => {
        if (!cancelled) setLoadingConversation(false)
      })

    return () => {
      cancelled = true
    }
  }, [workspaceUuid, routeUuid, t])

  const startNewChat = () => {
    navigate('/ai-workspace/chat')
    setConversationUuid(null)
    setMessages([])
    setError(null)
    setInput('')
  }

  if (!hasWorkspace || !workspaceUuid) return <NoWorkspaceNotice />

  const send = async () => {
    const text = input.trim()
    if (!text || sending || loadingConversation) return
    setSending(true)
    setError(null)

    const userMsg: AiConversationMessage = {
      uuid: `local-${Date.now()}`,
      role: 'user',
      content: text,
      prompt_tokens: 0,
      completion_tokens: 0,
      total_tokens: 0,
      mocked: false,
      created_at: new Date().toISOString(),
    }
    const priorHistory = messages.map((m) => ({
      role: m.role as 'user' | 'assistant',
      content: m.content ?? '',
    }))
    setMessages((prev) => [...prev, userMsg])
    setInput('')

    try {
      let convUuid = conversationUuid
      if (!convUuid) {
        const conv = await aiWorkspaceService.createConversation(workspaceUuid, {
          title: text.slice(0, 80),
        })
        convUuid = conv.uuid
        setConversationUuid(convUuid)
        navigate(`/ai-workspace/chat/${convUuid}`, { replace: true })
        recent.reload()
      }
      const res = await aiWorkspaceService.sendMessage(workspaceUuid, convUuid, {
        message: text,
        history: priorHistory,
      })
      setRuntimeMode(res.runtime_mode ?? (res.mocked ? 'mock' : res.ai_enabled ? 'live' : 'disabled'))
      setMessages((prev) => [
        ...prev,
        {
          uuid: res.message_uuid,
          role: 'assistant',
          content: res.content,
          prompt_tokens: res.usage.prompt_tokens,
          completion_tokens: res.usage.completion_tokens,
          total_tokens: res.usage.total_tokens,
          mocked: res.mocked,
          created_at: res.generated_at,
        },
      ])
      recent.reload()
    } catch (err: unknown) {
      setMessages((prev) => prev.filter((m) => m.uuid !== userMsg.uuid))
      setInput(text)
      const reqErr = err as { userMessage?: string; message?: string }
      setError(reqErr.userMessage ?? (err instanceof Error ? err.message : t('aiWorkspace.chat.sendError')))
    } finally {
      setSending(false)
    }
  }

  const recentRows = (recent.data ?? []).slice(0, 20)

  return (
    <div className="flex flex-col gap-4 lg:flex-row">
      {/* Recent conversations sidebar */}
      <aside className="w-full shrink-0 lg:w-64">
        <Card className="flex max-h-[60vh] flex-col p-3 lg:max-h-[70vh]">
          <div className="mb-3 flex items-center justify-between gap-2">
            <h2 className="text-xs font-semibold uppercase tracking-wide text-white/50">
              {t('aiWorkspace.chat.recentTitle')}
            </h2>
            <button
              type="button"
              onClick={startNewChat}
              className="rounded-full bg-sky-500/20 px-2.5 py-1 text-[10px] font-semibold text-sky-200 hover:bg-sky-500/30"
            >
              {t('aiWorkspace.chat.newChat')}
            </button>
          </div>
          <div className="min-h-0 flex-1 space-y-1 overflow-y-auto">
            {recent.loading ? (
              <p className="text-xs text-white/40">{t('aiWorkspace.common.loading')}</p>
            ) : recentRows.length === 0 ? (
              <p className="text-xs text-white/40">{t('aiWorkspace.chat.noRecent')}</p>
            ) : (
              recentRows.map((c) => {
                const active = c.uuid === conversationUuid
                return (
                  <Link
                    key={c.uuid}
                    to={`/ai-workspace/chat/${c.uuid}`}
                    className={[
                      'block rounded-lg px-2.5 py-2 text-start transition',
                      active ? 'bg-sky-500/20 ring-1 ring-sky-400/40' : 'hover:bg-white/5',
                    ].join(' ')}
                  >
                    <p className="truncate text-xs font-medium text-white">{conversationLabel(c)}</p>
                    <p className="text-[10px] text-white/40">
                      {formatDateTime(c.updated_at ?? c.created_at)} · {c.message_count}{' '}
                      {t('aiWorkspace.conversations.messages').toLowerCase()}
                    </p>
                  </Link>
                )
              })
            )}
          </div>
          <Link
            to="/ai-workspace/conversations"
            className="mt-2 block text-center text-[10px] text-sky-300/80 hover:text-sky-200"
          >
            {t('aiWorkspace.chat.viewAll')}
          </Link>
        </Card>
      </aside>

      {/* Main chat */}
      <div className="min-w-0 flex-1 space-y-4">
        {knowledgeActive ? (
          <div className="rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 py-2 text-xs text-emerald-100">
            {t('aiWorkspace.chat.knowledgeActive')}
          </div>
        ) : summary.data && summary.data.summary.runtime_mode === 'live' ? (
          <div className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-xs text-amber-100">
            {t('aiWorkspace.chat.knowledgeMissing')}
          </div>
        ) : null}

        {runtimeMode === 'mock' ? (
          <div className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-xs text-amber-100">
            {t('aiWorkspace.chat.mockedNotice')}
          </div>
        ) : null}

        <Card className="flex h-[60vh] flex-col lg:h-[70vh]">
          <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto pe-1">
            {loadingConversation ? (
              <p className="text-sm text-white/50">{t('aiWorkspace.chat.loadingConversation')}</p>
            ) : messages.length === 0 ? (
              <p className="text-sm text-white/50">{t('aiWorkspace.chat.empty')}</p>
            ) : (
              messages.map((m) => (
                <div key={m.uuid} className={m.role === 'user' ? 'text-end' : 'text-start'}>
                  <span
                    className={[
                      'inline-block max-w-[85%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm',
                      m.role === 'user' ? 'bg-sky-500 text-white' : 'bg-white/10 text-white/90',
                    ].join(' ')}
                  >
                    {m.content ?? ''}
                  </span>
                </div>
              ))
            )}

            {sending ? <AiChatTypingIndicator knowledgeBase={knowledgeActive} /> : null}
            <div ref={endRef} />
          </div>

          {error ? <AiError message={error} /> : null}

          <div className="mt-3 flex items-end gap-2 border-t border-white/10 pt-3">
            <textarea
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault()
                  void send()
                }
              }}
              rows={2}
              disabled={sending || loadingConversation}
              placeholder={t('aiWorkspace.chat.placeholder')}
              className="flex-1 resize-none rounded-xl border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none disabled:opacity-50"
            />
            <button
              type="button"
              onClick={() => void send()}
              disabled={sending || loadingConversation || input.trim() === ''}
              className="flex min-w-[5.5rem] items-center justify-center gap-1.5 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
            >
              {sending ? (
                <>
                  <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                  <span>{t('aiWorkspace.chat.sending')}</span>
                </>
              ) : (
                t('aiWorkspace.chat.send')
              )}
            </button>
          </div>
        </Card>
      </div>
    </div>
  )
}

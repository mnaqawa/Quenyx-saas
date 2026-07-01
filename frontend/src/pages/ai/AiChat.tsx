import { useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiError, Card, NoWorkspaceNotice } from '../../components/ai/workspace/shared'
import type { AiConversationMessage, AiRuntimeMode } from '../../types/aiWorkspace'

/**
 * AI Chat — creates a real conversation on first send and runs through the platform AI runtime.
 * Live providers are used when configured; mock is only shown in local/testing or explicit safe mode.
 */
export default function AiChat() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [conversationUuid, setConversationUuid] = useState<string | null>(null)
  const [messages, setMessages] = useState<AiConversationMessage[]>([])
  const [input, setInput] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [runtimeMode, setRuntimeMode] = useState<AiRuntimeMode | null>(null)

  if (!hasWorkspace || !workspaceUuid) return <NoWorkspaceNotice />

  const send = async () => {
    const text = input.trim()
    if (!text || sending) return
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
    setMessages((prev) => [...prev, userMsg])
    setInput('')

    try {
      let convUuid = conversationUuid
      if (!convUuid) {
        const conv = await aiWorkspaceService.createConversation(workspaceUuid, {})
        convUuid = conv.uuid
        setConversationUuid(convUuid)
      }
      const res = await aiWorkspaceService.sendMessage(workspaceUuid, convUuid, { message: text })
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
    } catch (err: unknown) {
      const reqErr = err as { userMessage?: string; message?: string }
      setError(reqErr.userMessage ?? (err instanceof Error ? err.message : t('aiWorkspace.chat.sendError')))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="space-y-4">
      {runtimeMode === 'mock' ? (
        <div className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-xs text-amber-100">
          {t('aiWorkspace.chat.mockedNotice')}
        </div>
      ) : null}

      <Card className="flex h-[60vh] flex-col">
        <div className="flex-1 space-y-3 overflow-y-auto pe-1">
          {messages.length === 0 ? (
            <p className="text-sm text-white/50">{t('aiWorkspace.chat.empty')}</p>
          ) : (
            messages.map((m) => (
              <div key={m.uuid} className={m.role === 'user' ? 'text-end' : 'text-start'}>
                <span
                  className={[
                    'inline-block max-w-[80%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm',
                    m.role === 'user' ? 'bg-sky-500 text-white' : 'bg-white/10 text-white/90',
                  ].join(' ')}
                >
                  {m.content ?? ''}
                </span>
              </div>
            ))
          )}
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
            placeholder={t('aiWorkspace.chat.placeholder')}
            className="flex-1 resize-none rounded-xl border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
          />
          <button
            type="button"
            onClick={() => void send()}
            disabled={sending || input.trim() === ''}
            className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
          >
            {sending ? t('aiWorkspace.chat.sending') : t('aiWorkspace.chat.send')}
          </button>
        </div>
      </Card>
    </div>
  )
}

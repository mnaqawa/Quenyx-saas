import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { queryAiAgent } from '../../services/aiAgentService'
import type {
  AIAgentContext,
  AIAgentQueryRequest,
  AIAgentSeed,
  AIAgentTab,
  AIAgentType,
  ChatMessage,
} from '../../types/aiAgent'

interface AIAgentDrawerProps {
  open: boolean
  onClose: () => void
  /** Workspace the questions are scoped to (sent for server-side membership check). */
  workspaceId?: number | null
  /** Agent tab selected when the drawer first opens. */
  defaultAgent?: AIAgentType
  /** Optional seed to prefill / auto-send a question when its `id` changes. */
  seed?: AIAgentSeed | null
}

const AGENT_TABS: AIAgentTab[] = [
  {
    key: 'performance_analyst',
    label: 'Performance Analyst',
    description: 'Analyze performance metrics and identify bottlenecks.',
    activeClass: 'border-sky-500/50 bg-sky-500/15 text-sky-100',
    bubbleClass: 'border-sky-500/30 bg-sky-500/10 text-sky-50',
    dotClass: 'bg-sky-400',
  },
  {
    key: 'anomaly_detector',
    label: 'Anomaly Detector',
    description: 'Detect anomalies and unusual system behaviour.',
    activeClass: 'border-amber-500/50 bg-amber-500/15 text-amber-100',
    bubbleClass: 'border-amber-500/30 bg-amber-500/10 text-amber-50',
    dotClass: 'bg-amber-400',
  },
  {
    key: 'compliance',
    label: 'Compliance (NCA/SAMA)',
    description: 'NCA and SAMA compliance guidance.',
    activeClass: 'border-emerald-500/50 bg-emerald-500/15 text-emerald-100',
    bubbleClass: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-50',
    dotClass: 'bg-emerald-400',
  },
  {
    key: 'capacity_planner',
    label: 'Capacity Planner',
    description: 'Predict future infrastructure requirements.',
    activeClass: 'border-purple-500/50 bg-purple-500/15 text-purple-100',
    bubbleClass: 'border-purple-500/30 bg-purple-500/10 text-purple-50',
    dotClass: 'bg-purple-400',
  },
]

const MAX_QUESTION_LENGTH = 5000

const EMPTY_CONVERSATIONS: Record<AIAgentType, ChatMessage[]> = {
  performance_analyst: [],
  anomaly_detector: [],
  compliance: [],
  capacity_planner: [],
}

function newId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

function formatTimestamp(ms: number): string {
  return new Date(ms).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function AIAgentDrawer({ open, onClose, workspaceId, defaultAgent, seed }: AIAgentDrawerProps) {
  const [activeAgent, setActiveAgent] = useState<AIAgentType>(defaultAgent ?? 'performance_analyst')
  const [conversations, setConversations] =
    useState<Record<AIAgentType, ChatMessage[]>>(EMPTY_CONVERSATIONS)
  const [input, setInput] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [lastSeedId, setLastSeedId] = useState<number | null>(null)

  const abortRef = useRef<AbortController | null>(null)
  const endRef = useRef<HTMLDivElement | null>(null)

  const activeTab = useMemo(
    () => AGENT_TABS.find((tab) => tab.key === activeAgent) ?? AGENT_TABS[0],
    [activeAgent]
  )
  const messages = conversations[activeAgent]

  const send = useCallback(
    async (rawQuestion: string, agent: AIAgentType, context?: AIAgentContext) => {
      const question = rawQuestion.trim()
      if (!question || sending) return

      const userMessage: ChatMessage = {
        id: newId(),
        role: 'user',
        agent,
        content: question,
        timestamp: Date.now(),
      }

      setConversations((prev) => ({ ...prev, [agent]: [...prev[agent], userMessage] }))
      setInput('')
      setError(null)
      setSending(true)

      const controller = new AbortController()
      abortRef.current = controller

      const body: AIAgentQueryRequest = { agent, question }
      if (workspaceId != null) body.workspace_id = workspaceId
      if (context) body.context = context

      try {
        const result = await queryAiAgent(body, controller.signal)
        const assistantMessage: ChatMessage = {
          id: newId(),
          role: 'assistant',
          agent,
          content: result.answer,
          timestamp: Date.now(),
        }
        setConversations((prev) => ({ ...prev, [agent]: [...prev[agent], assistantMessage] }))
      } catch (err) {
        if (controller.signal.aborted) return
        const message = err instanceof Error ? err.message : 'The AI agent request failed.'
        setError(message)
        const assistantMessage: ChatMessage = {
          id: newId(),
          role: 'assistant',
          agent,
          content: message,
          timestamp: Date.now(),
          isError: true,
        }
        setConversations((prev) => ({ ...prev, [agent]: [...prev[agent], assistantMessage] }))
      } finally {
        if (!controller.signal.aborted) {
          setSending(false)
          abortRef.current = null
        }
      }
    },
    [sending, workspaceId]
  )

  // Abort any in-flight request when the drawer closes or unmounts.
  useEffect(() => {
    if (!open) {
      abortRef.current?.abort()
      abortRef.current = null
      setSending(false)
    }
    return () => {
      abortRef.current?.abort()
    }
  }, [open])

  // Auto-scroll to the latest message.
  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end', behavior: 'smooth' })
  }, [messages, sending])

  // Apply an incoming seed (prefill, switch tab, optionally auto-send).
  useEffect(() => {
    if (!open || !seed || seed.id === lastSeedId) return
    setLastSeedId(seed.id)
    const agent = seed.agent ?? activeAgent
    setActiveAgent(agent)
    setError(null)
    if (seed.autoSend) {
      void send(seed.question, agent, seed.context)
    } else {
      setInput(seed.question)
    }
  }, [open, seed, lastSeedId, activeAgent, send])

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    void send(input, activeAgent)
  }

  const stop = () => {
    abortRef.current?.abort()
    abortRef.current = null
    setSending(false)
  }

  const clearConversation = () => {
    if (sending) return
    setConversations((prev) => ({ ...prev, [activeAgent]: [] }))
    setError(null)
  }

  if (!open) return null

  return (
    <>
      <div
        className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm sm:bg-black/40"
        onClick={onClose}
        aria-hidden="true"
      />
      <aside
        className="fixed inset-y-0 right-0 z-50 flex h-full w-full max-w-full flex-col border-l border-white/10 bg-[#0f151d] text-white shadow-2xl sm:w-[440px] sm:max-w-[90vw]"
        role="dialog"
        aria-modal="true"
        aria-label="AI Agent"
      >
        {/* Header */}
        <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
          <div className="flex items-center gap-2">
            <span className="rounded-lg bg-orange-500/20 px-2 py-1 text-xs font-semibold text-orange-200">
              AI Agent
            </span>
            <span className="flex items-center gap-1 rounded-full border border-emerald-500/30 px-2 py-0.5 text-[10px] font-semibold text-emerald-300">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
              KNOWLEDGE BASE
            </span>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-white/10 px-2 py-1 text-sm text-white/60 hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-white/20"
            aria-label="Close AI Agent"
          >
            ✕
          </button>
        </div>

        {/* Tabs */}
        <div className="grid grid-cols-2 gap-2 border-b border-white/10 p-3">
          {AGENT_TABS.map((tab) => (
            <button
              key={tab.key}
              type="button"
              onClick={() => {
                setActiveAgent(tab.key)
                setError(null)
              }}
              aria-pressed={activeAgent === tab.key}
              className={`rounded-lg border px-3 py-2 text-left text-xs font-semibold transition ${
                activeAgent === tab.key
                  ? tab.activeClass
                  : 'border-white/10 bg-white/5 text-white/65 hover:bg-white/10'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Active agent description */}
        <div className="flex items-center justify-between gap-2 border-b border-white/10 px-4 py-2">
          <p className="text-xs text-white/55">{activeTab.description}</p>
          {messages.length > 0 && (
            <button
              type="button"
              onClick={clearConversation}
              disabled={sending}
              className="shrink-0 rounded-md border border-white/10 px-2 py-1 text-[10px] font-medium text-white/50 hover:bg-white/10 hover:text-white/80 disabled:cursor-not-allowed disabled:opacity-40"
            >
              Clear
            </button>
          )}
        </div>

        {/* Conversation */}
        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
          {messages.length === 0 && !sending && (
            <div className="rounded-xl border border-white/10 bg-black/20 p-4 text-xs leading-relaxed text-white/55">
              Ask <span className="font-semibold text-white/75">{activeTab.label}</span> a question.
              Answers are grounded in your knowledge base via File Search.
            </div>
          )}

          {messages.map((message) => (
            <div
              key={message.id}
              className={`flex flex-col ${message.role === 'user' ? 'items-end' : 'items-start'}`}
            >
              <div
                className={`max-w-[85%] whitespace-pre-wrap break-words rounded-2xl border px-3 py-2 text-sm ${
                  message.role === 'user'
                    ? 'rounded-br-sm border-orange-500/30 bg-orange-500/15 text-orange-50'
                    : message.isError
                      ? 'rounded-bl-sm border-rose-500/40 bg-rose-500/10 text-rose-100'
                      : `rounded-bl-sm ${activeTab.bubbleClass}`
                }`}
              >
                {message.content}
              </div>
              <span className="mt-1 px-1 text-[10px] text-white/35">
                {message.role === 'user' ? 'You' : activeTab.label} · {formatTimestamp(message.timestamp)}
              </span>
            </div>
          ))}

          {sending && (
            <div className="flex flex-col items-start">
              <div className={`flex items-center gap-1.5 rounded-2xl rounded-bl-sm border px-3 py-2.5 ${activeTab.bubbleClass}`}>
                <span className={`h-1.5 w-1.5 animate-bounce rounded-full ${activeTab.dotClass} [animation-delay:-0.3s]`} />
                <span className={`h-1.5 w-1.5 animate-bounce rounded-full ${activeTab.dotClass} [animation-delay:-0.15s]`} />
                <span className={`h-1.5 w-1.5 animate-bounce rounded-full ${activeTab.dotClass}`} />
              </div>
              <span className="mt-1 px-1 text-[10px] text-white/35">{activeTab.label} is thinking…</span>
            </div>
          )}

          <div ref={endRef} />
        </div>

        {/* Error banner */}
        {error && (
          <div className="border-t border-rose-500/20 bg-rose-500/5 px-4 py-2">
            <p className="text-xs text-rose-200" role="alert">
              {error}
            </p>
          </div>
        )}

        {/* Composer */}
        <form onSubmit={handleSubmit} className="border-t border-white/10 p-3">
          <div className="flex items-end gap-2">
            <textarea
              value={input}
              onChange={(event) => setInput(event.target.value.slice(0, MAX_QUESTION_LENGTH))}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                  event.preventDefault()
                  void send(input, activeAgent)
                }
              }}
              rows={2}
              disabled={sending}
              placeholder={`Ask ${activeTab.label}…`}
              className="min-w-0 flex-1 resize-none rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white placeholder:text-white/35 focus:border-orange-500/60 focus:outline-none disabled:opacity-60"
            />
            {sending ? (
              <button
                type="button"
                onClick={stop}
                className="shrink-0 rounded-lg border border-white/20 px-3 py-2 text-xs font-semibold text-white/70 hover:bg-white/10"
              >
                Stop
              </button>
            ) : (
              <button
                type="submit"
                disabled={!input.trim()}
                className="shrink-0 rounded-lg bg-orange-500 px-4 py-2 text-xs font-semibold text-white hover:bg-orange-400 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Send
              </button>
            )}
          </div>
          <div className="mt-1 flex items-center justify-between px-1 text-[10px] text-white/30">
            <span>Enter to send · Shift+Enter for newline</span>
            <span>
              {input.length}/{MAX_QUESTION_LENGTH}
            </span>
          </div>
        </form>
      </aside>
    </>
  )
}

export default AIAgentDrawer

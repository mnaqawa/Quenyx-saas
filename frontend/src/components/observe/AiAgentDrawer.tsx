// LEGACY component.
// Superseded by /api/ai-agent/query and components/ai/AIAgentDrawer.tsx (OpenAI Responses API + File Search).
// TODO: Remove once all entry points migrate to the knowledge-base agent. Kept for backward compatibility.
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import {
  aiAgentService,
  type AiChatTurn,
  type AiPersona,
  type AiPersonaKey,
  type AiPersonasResponse,
} from '../../services/aiAgentService'

export interface AiAnalyzeRequest {
  id: number
  host?: string
  persona?: AiPersonaKey
}

interface UiMessage extends AiChatTurn {
  id: string
}

interface AiAgentDrawerProps {
  workspaceId: number | null
  open: boolean
  analyzeRequest?: AiAnalyzeRequest | null
  onClose: () => void
}

const DEFAULT_PERSONAS: AiPersona[] = [
  {
    key: 'performance_analyst',
    label: 'Performance Analyst',
    description: 'Analyze CPU, memory, disk, load and bottlenecks.',
    quick_action: 'Summarize current performance across all servers and call out the top risks.',
  },
  {
    key: 'anomaly_detector',
    label: 'Anomaly Detector',
    description: 'Detect deviations from current baselines.',
    quick_action: 'Detect anomalies across all servers and rank them by severity.',
  },
  {
    key: 'compliance',
    label: 'Compliance (NCA/SAMA)',
    description: 'Assess monitoring coverage for regulated GCC controls.',
    quick_action: 'Assess monitoring coverage against NCA ECC and SAMA CSF and list gaps.',
  },
  {
    key: 'capacity_planner',
    label: 'Capacity Planner',
    description: 'Estimate headroom and upcoming capacity risks.',
    quick_action: 'Project capacity headroom for each server and flag what will run out first.',
  },
]

function nextMessageId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

function personaTone(persona: AiPersonaKey): string {
  const tones: Record<AiPersonaKey, string> = {
    performance_analyst: 'border-sky-500/40 bg-sky-500/10 text-sky-200',
    anomaly_detector: 'border-amber-500/40 bg-amber-500/10 text-amber-200',
    compliance: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200',
    capacity_planner: 'border-purple-500/40 bg-purple-500/10 text-purple-200',
  }
  return tones[persona]
}

export function AiAgentDrawer({ workspaceId, open, analyzeRequest, onClose }: AiAgentDrawerProps) {
  const [metadata, setMetadata] = useState<AiPersonasResponse | null>(null)
  const [activePersona, setActivePersona] = useState<AiPersonaKey>('performance_analyst')
  const [messages, setMessages] = useState<UiMessage[]>([])
  const [input, setInput] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loadingPersonas, setLoadingPersonas] = useState(false)
  const [sending, setSending] = useState(false)
  const [focusedHost, setFocusedHost] = useState<string | null>(null)
  const [lastHandledAnalyzeId, setLastHandledAnalyzeId] = useState<number | null>(null)
  const abortRef = useRef<AbortController | null>(null)
  const endRef = useRef<HTMLDivElement | null>(null)

  const personas = metadata?.personas.length ? metadata.personas : DEFAULT_PERSONAS
  const activePersonaMeta = useMemo(
    () => personas.find((p) => p.key === activePersona) ?? personas[0],
    [activePersona, personas]
  )

  useEffect(() => {
    if (!open || !workspaceId) return
    let cancelled = false
    setLoadingPersonas(true)
    setError(null)

    aiAgentService
      .getPersonas(workspaceId)
      .then((data) => {
        if (cancelled) return
        setMetadata(data)
        if (data.personas.length > 0 && !data.personas.some((p) => p.key === activePersona)) {
          setActivePersona(data.personas[0].key)
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return
        setError(err instanceof Error ? err.message : 'Failed to load AI agent metadata.')
      })
      .finally(() => {
        if (!cancelled) setLoadingPersonas(false)
      })

    return () => {
      cancelled = true
    }
  }, [activePersona, open, workspaceId])

  useEffect(() => {
    if (!open) {
      abortRef.current?.abort()
      abortRef.current = null
      setSending(false)
    }
  }, [open])

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end', behavior: 'smooth' })
  }, [messages, sending])

  const sendMessage = useCallback(
    async (message: string, options?: { host?: string; persona?: AiPersonaKey }) => {
      if (!workspaceId || !metadata?.available || sending) return
      const trimmed = message.trim()
      if (!trimmed) return

      const persona = options?.persona ?? activePersona
      const host = options?.host ?? focusedHost ?? undefined
      const history: AiChatTurn[] = messages.map(({ role, content }) => ({ role, content }))
      const userMessage: UiMessage = { id: nextMessageId(), role: 'user', content: trimmed }
      const assistantId = nextMessageId()

      setActivePersona(persona)
      setFocusedHost(host ?? null)
      setMessages((prev) => [
        ...prev,
        userMessage,
        { id: assistantId, role: 'assistant', content: '' },
      ])
      setInput('')
      setError(null)
      setSending(true)

      const controller = new AbortController()
      abortRef.current = controller

      try {
        await aiAgentService.streamChat(
          workspaceId,
          { persona, message: trimmed, host, history },
          (delta) => {
            setMessages((prev) =>
              prev.map((msg) =>
                msg.id === assistantId ? { ...msg, content: msg.content + delta } : msg
              )
            )
          },
          controller.signal
        )
      } catch (err) {
        if (controller.signal.aborted) return
        setMessages((prev) => prev.filter((msg) => msg.id !== assistantId))
        setError(err instanceof Error ? err.message : 'AI agent request failed.')
      } finally {
        if (!controller.signal.aborted) {
          setSending(false)
          abortRef.current = null
        }
      }
    },
    [activePersona, focusedHost, messages, metadata?.available, sending, workspaceId]
  )

  useEffect(() => {
    if (!open || !workspaceId || !metadata?.available || !analyzeRequest) return
    if (lastHandledAnalyzeId === analyzeRequest.id) return

    setLastHandledAnalyzeId(analyzeRequest.id)
    const persona = analyzeRequest.persona ?? activePersona
    const host = analyzeRequest.host
    const meta = personas.find((p) => p.key === persona) ?? activePersonaMeta
    void sendMessage(host ? `Analyze host "${host}". ${meta.quick_action}` : meta.quick_action, {
      host,
      persona,
    })
  }, [
    activePersona,
    activePersonaMeta,
    analyzeRequest,
    lastHandledAnalyzeId,
    metadata?.available,
    open,
    personas,
    sendMessage,
    workspaceId,
  ])

  const quickAction = () => {
    void sendMessage(activePersonaMeta.quick_action, {
      host: focusedHost ?? undefined,
      persona: activePersona,
    })
  }

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    void sendMessage(input)
  }

  const stop = () => {
    abortRef.current?.abort()
    abortRef.current = null
    setSending(false)
  }

  if (!open) return null

  return (
    <aside className="fixed right-0 top-0 z-50 flex h-screen w-full max-w-[390px] flex-col border-l border-white/10 bg-[#0f151d] text-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
        <div>
          <div className="flex items-center gap-2">
            <span className="rounded-lg bg-orange-500/20 px-2 py-1 text-xs font-semibold text-orange-200">
              AI Agent
            </span>
            <span className="rounded-full border border-emerald-500/30 px-2 py-0.5 text-[10px] font-semibold text-emerald-300">
              {metadata?.available ? 'LIVE' : 'OFFLINE'}
            </span>
          </div>
          {focusedHost && <p className="mt-1 text-xs text-white/50">Focused on {focusedHost}</p>}
        </div>
        <button
          type="button"
          onClick={onClose}
          className="rounded-lg border border-white/10 px-2 py-1 text-sm text-white/60 hover:bg-white/10 hover:text-white"
          aria-label="Close AI Agent"
        >
          x
        </button>
      </div>

      <div className="grid grid-cols-2 gap-2 border-b border-white/10 p-3">
        {personas.map((persona) => (
          <button
            key={persona.key}
            type="button"
            onClick={() => setActivePersona(persona.key)}
            className={`rounded-lg border px-3 py-2 text-left text-xs transition ${
              activePersona === persona.key
                ? personaTone(persona.key)
                : 'border-white/10 bg-white/5 text-white/65 hover:bg-white/10'
            }`}
          >
            <span className="block font-semibold">{persona.label}</span>
          </button>
        ))}
      </div>

      <div className="border-b border-white/10 p-3">
        <p className="text-xs text-white/55">{activePersonaMeta.description}</p>
        <button
          type="button"
          onClick={quickAction}
          disabled={!metadata?.available || sending}
          className="mt-3 w-full rounded-lg border border-orange-500/40 bg-orange-500/15 px-3 py-2 text-xs font-semibold text-orange-100 hover:bg-orange-500/25 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {activePersonaMeta.quick_action}
        </button>
      </div>

      {(loadingPersonas || error || metadata?.reason) && (
        <div className="border-b border-white/10 p-3 text-xs">
          {loadingPersonas && <p className="text-white/50">Loading AI agent...</p>}
          {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-2 text-rose-100">{error}</p>}
          {metadata?.reason && (
            <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-2 text-amber-100">
              {metadata.reason}
            </p>
          )}
        </div>
      )}

      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
        {messages.length === 0 && (
          <div className="rounded-xl border border-white/10 bg-black/20 p-4 text-xs text-white/55">
            Ask the agent about live telemetry, anomalies, compliance gaps, or capacity risk. Answers are grounded in the workspace data sent by the backend.
          </div>
        )}

        {messages.map((message) => (
          <div
            key={message.id}
            className={`rounded-xl border p-3 text-sm ${
              message.role === 'user'
                ? 'ml-8 border-orange-500/30 bg-orange-500/10 text-orange-50'
                : 'mr-8 border-white/10 bg-black/25 text-white/85'
            }`}
          >
            {message.content || (message.role === 'assistant' ? 'Thinking...' : '')}
          </div>
        ))}
        <div ref={endRef} />
      </div>

      <form onSubmit={submit} className="border-t border-white/10 p-3">
        <div className="flex gap-2">
          <input
            value={input}
            onChange={(event) => setInput(event.target.value)}
            disabled={!metadata?.available || sending}
            placeholder={`Ask ${activePersonaMeta.label}...`}
            className="min-w-0 flex-1 rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white placeholder:text-white/35 focus:border-orange-500/60 focus:outline-none"
          />
          {sending ? (
            <button
              type="button"
              onClick={stop}
              className="rounded-lg border border-white/20 px-3 py-2 text-xs text-white/70 hover:bg-white/10"
            >
              Stop
            </button>
          ) : (
            <button
              type="submit"
              disabled={!metadata?.available || !input.trim()}
              className="rounded-lg bg-orange-500 px-3 py-2 text-xs font-semibold text-white hover:bg-orange-400 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Send
            </button>
          )}
        </div>
      </form>
    </aside>
  )
}

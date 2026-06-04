// Types for the knowledge-base AI agent backed by POST /api/ai-agent/query
// (OpenAI Responses API + File Search over the configured Vector Store).

export type AIAgentType =
  | 'performance_analyst'
  | 'anomaly_detector'
  | 'compliance'
  | 'capacity_planner'

/** Static metadata used to render the agent tabs in the drawer. */
export interface AIAgentTab {
  key: AIAgentType
  label: string
  description: string
  /** Tailwind classes for the active tab + accents (kept static for JIT). */
  activeClass: string
  bubbleClass: string
  dotClass: string
}

/** Optional QynSight operational context attached to a query. */
export interface AIAgentContext {
  source?: string
  host?: string
  metrics?: Record<string, unknown>
  services?: unknown[]
}

export interface AIAgentQueryRequest {
  agent: AIAgentType
  question: string
  /** Optional workspace the question is scoped to (membership verified server-side). */
  workspace_id?: number | null
  /** Optional QynSight runtime context injected into the model prompt. */
  context?: AIAgentContext | null
}

export interface AIAgentQueryMeta {
  model: string | null
  response_id: string | null
  total_tokens: number | null
}

/** Success payload from the backend controller. */
export interface AIAgentQueryResponse {
  success: true
  answer: string
  agent: AIAgentType
  meta: AIAgentQueryMeta
}

/** Error payload from the backend controller (non-validation failures). */
export interface AIAgentErrorResponse {
  success: false
  code: string
  message: string
}

export type ChatRole = 'user' | 'assistant'

/** A single turn stored in the drawer's local conversation state. */
export interface ChatMessage {
  id: string
  role: ChatRole
  agent: AIAgentType
  content: string
  /** Epoch milliseconds when the message was created. */
  timestamp: number
  /** True when the assistant turn represents an error response. */
  isError?: boolean
}

/**
 * Optional seed to prefill (and optionally auto-send) a question when the
 * drawer opens, e.g. from a "Analyze with AI" action elsewhere in the UI.
 */
export interface AIAgentSeed {
  id: number
  question: string
  agent?: AIAgentType
  autoSend?: boolean
  /** Optional context to attach to the seeded question (e.g. host analysis). */
  context?: AIAgentContext
}

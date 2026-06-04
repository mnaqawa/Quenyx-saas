import { getAuthToken } from './apiClient'
import { gatewayClient } from './gatewayClient'
import type { AIAgentQueryRequest, AIAgentQueryResponse } from '../types/aiAgent'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''

export type AiPersonaKey =
  | 'performance_analyst'
  | 'anomaly_detector'
  | 'compliance'
  | 'capacity_planner'

export interface AiPersona {
  key: AiPersonaKey
  label: string
  description: string
  quick_action: string
}

export interface AiPersonasResponse {
  available: boolean
  reason: string | null
  model: string | null
  personas: AiPersona[]
}

export interface AiChatTurn {
  role: 'user' | 'assistant'
  content: string
}

export interface AiChatRequest {
  persona: AiPersonaKey
  message: string
  host?: string
  history?: AiChatTurn[]
}

export interface AiChatResponse {
  reply: string
  persona: AiPersonaKey
  model: string
  usage: {
    prompt_tokens: number
    completion_tokens: number
    total_tokens: number
  }
}

/** Stable, machine-readable error from the knowledge-base agent endpoint. */
export class AiAgentError extends Error {
  readonly code: string
  readonly status: number

  constructor(message: string, code: string, status: number) {
    super(message)
    this.name = 'AiAgentError'
    this.code = code
    this.status = status
  }
}

interface AiAgentErrorBody {
  code?: string
  message?: string
  errors?: Record<string, string[] | string>
}

function flattenValidationErrors(errors: Record<string, string[] | string>): string {
  return Object.values(errors)
    .flatMap((value) => (Array.isArray(value) ? value : [value]))
    .filter((value): value is string => typeof value === 'string' && value.length > 0)
    .join(' ')
}

/**
 * Ask the knowledge base a question as a specific agent.
 *
 * Talks directly to POST /api/ai-agent/query. The endpoint returns a flat
 * { success, answer, agent, meta } envelope (no `data` wrapper), so this
 * bypasses the standard apiClient and parses the response itself.
 */
export async function queryAiAgent(
  body: AIAgentQueryRequest,
  signal?: AbortSignal
): Promise<AIAgentQueryResponse> {
  const token = getAuthToken()

  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}/api/ai-agent/query`, {
      method: 'POST',
      signal,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify(body),
    })
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') throw err
    throw new AiAgentError(
      'Could not reach the AI agent. Check your connection and try again.',
      'network_error',
      0
    )
  }

  let json: unknown = null
  try {
    json = await response.json()
  } catch {
    json = null
  }

  if (!response.ok) {
    const errorBody = (json ?? {}) as AiAgentErrorBody
    let message = errorBody.message || `AI agent request failed (${response.status}).`
    if (response.status === 422 && errorBody.errors) {
      message = flattenValidationErrors(errorBody.errors) || message
    }
    if (response.status === 401) {
      message = 'Your session has expired. Please sign in again.'
    }
    throw new AiAgentError(message, errorBody.code || 'request_failed', response.status)
  }

  const data = (json ?? {}) as Partial<AIAgentQueryResponse>
  if (data.success !== true || typeof data.answer !== 'string') {
    throw new AiAgentError(
      'The AI agent returned an unexpected response.',
      'invalid_response',
      response.status
    )
  }

  return data as AIAgentQueryResponse
}

function buildAiPath(workspaceId: number, path: string): string {
  return `workspaces/${workspaceId}/ai/${path}`
}

function buildDirectApiUrl(workspaceId: number, path: string): string {
  return `${API_BASE_URL}/api/${buildAiPath(workspaceId, path)}`
}

function extractSsePayload(line: string): string | null {
  const trimmed = line.trim()
  if (!trimmed.startsWith('data:')) return null
  return trimmed.slice('data:'.length).trim()
}

export const aiAgentService = {
  async getPersonas(workspaceId: number): Promise<AiPersonasResponse> {
    return gatewayClient.get<AiPersonasResponse>(buildAiPath(workspaceId, 'personas'), {
      workspaceId,
      moduleKey: 'qynsight',
    })
  },

  async chat(workspaceId: number, body: AiChatRequest): Promise<AiChatResponse> {
    return gatewayClient.post<AiChatResponse>(buildAiPath(workspaceId, 'chat'), body, {
      workspaceId,
      moduleKey: 'qynsight',
    })
  },

  async analyze(
    workspaceId: number,
    body: { persona?: AiPersonaKey; host?: string }
  ): Promise<AiChatResponse> {
    return gatewayClient.post<AiChatResponse>(buildAiPath(workspaceId, 'analyze'), body, {
      workspaceId,
      moduleKey: 'qynsight',
    })
  },

  async streamChat(
    workspaceId: number,
    body: AiChatRequest,
    onDelta: (text: string) => void,
    signal?: AbortSignal
  ): Promise<void> {
    const token = getAuthToken()
    const response = await fetch(buildDirectApiUrl(workspaceId, 'chat/stream'), {
      method: 'POST',
      signal,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
        'x-workspace-id': String(workspaceId),
        'x-module-key': 'qynsight',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify(body),
    })

    if (!response.ok) {
      let message = `AI agent failed (${response.status})`
      try {
        const json = (await response.json()) as { message?: string }
        if (json.message) message = json.message
      } catch {
        // Keep the HTTP status message if the server did not return JSON.
      }
      throw new Error(message)
    }

    if (!response.body) {
      throw new Error('AI stream did not return a response body.')
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''

    while (true) {
      const { done, value } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })
      const lines = buffer.split('\n')
      buffer = lines.pop() ?? ''

      for (const line of lines) {
        const payload = extractSsePayload(line)
        if (!payload) continue

        const parsed = JSON.parse(payload) as { text?: string; message?: string }
        if (parsed.text) {
          onDelta(parsed.text)
        }
        if (parsed.message) {
          throw new Error(parsed.message)
        }
      }
    }
  },
}

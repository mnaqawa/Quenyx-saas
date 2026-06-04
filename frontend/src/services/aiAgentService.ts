import { getAuthToken } from './apiClient'
import { gatewayClient } from './gatewayClient'

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

import { apiClient } from './apiClient'
import type { TicketAnalyzeResult, TicketCopilotResult, TicketDetail, TicketSummary } from '../types/support'

/**
 * Sprint 24 — QynSupport Service Desk API client. Workspace-UUID scoped, UUID-only tickets. AI triage is
 * evidence-based and editable; AI surfaces reuse the shared Quenyx AI conversation surface.
 */

const BASE = '/api/qynsupport'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

function body<T extends object>(workspaceUuid: string, extra: T): T & { workspace: string } {
  return { workspace: workspaceUuid, ...extra }
}

export const supportService = {
  list: (w: string, params?: { status?: string; priority?: string }) => {
    const qs = new URLSearchParams()
    if (params?.status) qs.set('status', params.status)
    if (params?.priority) qs.set('priority', params.priority)
    const suffix = qs.toString() ? `?${qs.toString()}` : ''
    return apiClient.get<{ tickets: TicketSummary[] }>(ws(`/tickets${suffix}`, w))
  },
  get: (w: string, uuid: string) => apiClient.get<TicketDetail>(ws(`/tickets/${uuid}`, w)),
  create: (w: string, payload: Record<string, unknown>) => apiClient.post<TicketDetail>(`${BASE}/tickets`, body(w, payload)),
  update: (w: string, uuid: string, payload: Record<string, unknown>) =>
    apiClient.put<TicketDetail>(`${BASE}/tickets/${uuid}`, body(w, payload)),

  analyze: (w: string, uuid: string) =>
    apiClient.post<TicketAnalyzeResult>(`${BASE}/tickets/${uuid}/intelligence/analyze`, body(w, {})),
  copilot: (w: string, uuid: string, message: string, conversation?: string) =>
    apiClient.post<TicketCopilotResult>(`${BASE}/tickets/${uuid}/intelligence/copilot`, body(w, { message, ...(conversation ? { conversation } : {}) })),
}

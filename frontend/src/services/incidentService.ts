import { apiClient } from './apiClient'
import type {
  IncidentCopilotResult,
  IncidentPostmortemResult,
  IncidentRecommendResult,
  IncidentSummary,
  IncidentWorkspaceData,
} from '../types/incident'

/**
 * Sprint 23 — QynReact Incident Workspace API client.
 *
 * Workspace-UUID scoped, UUID-only incident addressing. The unified incident view reuses Operations &
 * Asset Intelligence via the AI adapter registry (no module branching). AI surfaces reuse the shared
 * Quenyx AI conversation surface.
 */

const BASE = '/api/qynreact'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

function body<T extends object>(workspaceUuid: string, extra: T): T & { workspace: string } {
  return { workspace: workspaceUuid, ...extra }
}

export const incidentService = {
  list: (w: string, status?: string) => apiClient.get<{ incidents: IncidentSummary[] }>(ws(`/incidents${status ? `?status=${status}` : ''}`, w)),
  create: (w: string, payload: Record<string, unknown>) => apiClient.post<IncidentSummary>(`${BASE}/incidents`, body(w, payload)),
  get: (w: string, uuid: string) => apiClient.get<IncidentWorkspaceData>(ws(`/incidents/${uuid}`, w)),
  update: (w: string, uuid: string, payload: Record<string, unknown>) =>
    apiClient.put<IncidentSummary>(`${BASE}/incidents/${uuid}`, body(w, payload)),
  addTimeline: (w: string, uuid: string, payload: Record<string, unknown>) =>
    apiClient.post<unknown>(`${BASE}/incidents/${uuid}/timeline`, body(w, payload)),

  copilot: (w: string, uuid: string, message: string, conversation?: string) =>
    apiClient.post<IncidentCopilotResult>(`${BASE}/incidents/${uuid}/copilot`, body(w, { message, ...(conversation ? { conversation } : {}) })),
  recommend: (w: string, uuid: string) =>
    apiClient.post<IncidentRecommendResult>(`${BASE}/incidents/${uuid}/recommend`, body(w, {})),
  postmortem: (w: string, uuid: string) =>
    apiClient.post<IncidentPostmortemResult>(`${BASE}/incidents/${uuid}/postmortem`, body(w, {})),
}

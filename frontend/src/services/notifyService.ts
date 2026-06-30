import { apiClient } from './apiClient'
import type {
  NotificationCopilotResult,
  NotificationDigestResult,
  NotificationExecutiveResult,
  NotificationListResult,
  NotificationSummary,
} from '../types/notify'

/**
 * Sprint 24 — QynNotify Notification Center API client. Workspace-UUID scoped, UUID-only. Routing is
 * deterministic (real recipients, no fake routing); AI digests/summaries reuse the shared Quenyx AI
 * conversation surface.
 */

const BASE = '/api/qynnotify'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

function body<T extends object>(workspaceUuid: string, extra: T): T & { workspace: string } {
  return { workspace: workspaceUuid, ...extra }
}

export const notifyService = {
  list: (w: string, params?: { status?: string; severity?: string }) => {
    const qs = new URLSearchParams()
    if (params?.status) qs.set('status', params.status)
    if (params?.severity) qs.set('severity', params.severity)
    const suffix = qs.toString() ? `?${qs.toString()}` : ''
    return apiClient.get<NotificationListResult>(ws(`/notifications${suffix}`, w))
  },
  create: (w: string, payload: Record<string, unknown>) =>
    apiClient.post<NotificationSummary>(`${BASE}/notifications`, body(w, payload)),
  markRead: (w: string, uuid: string) => apiClient.post<NotificationSummary>(`${BASE}/notifications/${uuid}/read`, body(w, {})),

  digest: (w: string) => apiClient.post<NotificationDigestResult>(`${BASE}/intelligence/digest`, body(w, {})),
  executive: (w: string) => apiClient.post<NotificationExecutiveResult>(`${BASE}/intelligence/executive`, body(w, {})),
  copilot: (w: string, message: string, conversation?: string) =>
    apiClient.post<NotificationCopilotResult>(`${BASE}/intelligence/copilot`, body(w, { message, ...(conversation ? { conversation } : {}) })),
}

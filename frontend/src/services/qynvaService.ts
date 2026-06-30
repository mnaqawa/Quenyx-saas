import { apiClient } from './apiClient'
import type { AiNarrative } from '../types/automation'
import type {
  OperatorCapabilities,
  OperatorResult,
  ExecutiveDashboard,
  EnterpriseAnalytics,
  PlatformHealthSnapshot,
  EventBusIntrospection,
} from '../types/qynva'

/**
 * Sprint 25 — QynVA Enterprise AI Operator + Enterprise Intelligence (Executive, Analytics, Health, Bus).
 * Workspace scoping is explicit (query param for reads, body field for writes), matching the platform
 * service convention. AI surfaces reuse the shared Quenyx AI conversation surface.
 */
const BASE = '/api/qynva'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

export const qynvaService = {
  capabilities: (w: string) => apiClient.get<OperatorCapabilities>(ws('/operator/capabilities', w)),
  operate: (w: string, message: string, conversation?: string) =>
    apiClient.post<OperatorResult>(`${BASE}/operator/operate`, { workspace: w, message, conversation }),
  executive: (w: string) => apiClient.get<ExecutiveDashboard>(ws('/executive', w)),
  executiveSummary: (w: string) =>
    apiClient.post<{ dashboard: ExecutiveDashboard; executive_summary: AiNarrative }>(`${BASE}/executive/summary`, { workspace: w }),
  analytics: (w: string, days = 30) => apiClient.get<EnterpriseAnalytics>(ws(`/analytics?days=${days}`, w)),
  health: (w: string) => apiClient.get<PlatformHealthSnapshot>(ws('/health', w)),
  events: (w: string) => apiClient.get<EventBusIntrospection>(ws('/events', w)),
}

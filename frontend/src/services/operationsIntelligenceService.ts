import { apiClient } from './apiClient'
import type {
  OperationsCopilotResponse,
  OperationsOverview,
  OpsAiNarrative,
  OpsAlertExplanation,
  OpsIncidentTimeline,
  OpsRecommendationsResponse,
} from '../types/operationsIntelligence'

/**
 * Sprint 21 — QynSight Operations Intelligence API client.
 *
 * Reuses the same workspace-UUID scoping convention as the Unified AI Workspace (`?workspace=` for
 * reads, `workspace` body field for writes). All entity identifiers are UUIDs. The backend enforces
 * QynSight entitlement, monitoring RBAC, the `can_use_ai` capability, audit, and rate limiting.
 */

const BASE = '/api/qynsight/intelligence'

function withWorkspace(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

export const operationsIntelligenceService = {
  getOverview(workspaceUuid: string): Promise<OperationsOverview> {
    return apiClient.get<OperationsOverview>(withWorkspace('/overview', workspaceUuid))
  },

  getRecommendations(workspaceUuid: string): Promise<OpsRecommendationsResponse> {
    return apiClient.get<OpsRecommendationsResponse>(withWorkspace('/recommendations', workspaceUuid))
  },

  copilot(workspaceUuid: string, message: string, conversation?: string): Promise<OperationsCopilotResponse> {
    return apiClient.post<OperationsCopilotResponse>(`${BASE}/copilot`, {
      workspace: workspaceUuid,
      message,
      ...(conversation ? { conversation } : {}),
    })
  },

  explainAlert(workspaceUuid: string, uuid: string): Promise<OpsAlertExplanation> {
    return apiClient.post<OpsAlertExplanation>(`${BASE}/alerts/${uuid}/explain`, { workspace: workspaceUuid })
  },

  investigateAlert(workspaceUuid: string, uuid: string): Promise<Record<string, unknown>> {
    return apiClient.post<Record<string, unknown>>(`${BASE}/alerts/${uuid}/investigate`, { workspace: workspaceUuid })
  },

  incidentTimeline(workspaceUuid: string, uuid: string): Promise<OpsIncidentTimeline> {
    return apiClient.get<OpsIncidentTimeline>(withWorkspace(`/incidents/${uuid}/timeline`, workspaceUuid))
  },

  explainHost(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_explanation: OpsAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_explanation: OpsAiNarrative }>(`${BASE}/hosts/${uuid}/explain`, { workspace: workspaceUuid })
  },

  analyzeService(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_analysis: OpsAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_analysis: OpsAiNarrative }>(`${BASE}/services/${uuid}/analyze`, { workspace: workspaceUuid })
  },

  predictCapacity(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_explanation: OpsAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_explanation: OpsAiNarrative }>(`${BASE}/capacity/${uuid}/predict`, { workspace: workspaceUuid })
  },

  impact(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_explanation: OpsAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_explanation: OpsAiNarrative }>(`${BASE}/infrastructure/${uuid}/impact`, { workspace: workspaceUuid })
  },
}

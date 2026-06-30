import { apiClient } from './apiClient'
import type {
  AssetCopilotResponse,
  AssetLicenseReview,
  AssetOverview,
  AssetAiNarrative,
  AssetRecommendationsResponse,
} from '../types/assetIntelligence'

/**
 * Sprint 22 — QynAsset Asset Intelligence API client.
 *
 * Reuses the same workspace-UUID scoping convention as the Unified AI Workspace (`?workspace=` for
 * reads, `workspace` body field for writes). All entity identifiers are UUIDs. The backend enforces
 * QynAsset entitlement, RBAC, the `can_use_ai` capability, audit, and rate limiting, and never
 * fabricates inventory, lifecycle, or license data.
 */

const BASE = '/api/qynasset/intelligence'

function withWorkspace(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

export const assetIntelligenceService = {
  getOverview(workspaceUuid: string): Promise<AssetOverview> {
    return apiClient.get<AssetOverview>(withWorkspace('/overview', workspaceUuid))
  },

  getRecommendations(workspaceUuid: string): Promise<AssetRecommendationsResponse> {
    return apiClient.get<AssetRecommendationsResponse>(withWorkspace('/recommendations', workspaceUuid))
  },

  copilot(workspaceUuid: string, message: string, conversation?: string): Promise<AssetCopilotResponse> {
    return apiClient.post<AssetCopilotResponse>(`${BASE}/copilot`, {
      workspace: workspaceUuid,
      message,
      ...(conversation ? { conversation } : {}),
    })
  },

  explainAsset(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_explanation: AssetAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_explanation: AssetAiNarrative }>(`${BASE}/assets/${uuid}/explain`, { workspace: workspaceUuid })
  },

  analyzeDependency(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_analysis: AssetAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_analysis: AssetAiNarrative }>(`${BASE}/assets/${uuid}/dependencies`, { workspace: workspaceUuid })
  },

  forecastLifecycle(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_forecast: AssetAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_forecast: AssetAiNarrative }>(`${BASE}/assets/${uuid}/lifecycle`, { workspace: workspaceUuid })
  },

  relationshipImpact(workspaceUuid: string, uuid: string): Promise<Record<string, unknown> & { ai_explanation: AssetAiNarrative }> {
    return apiClient.post<Record<string, unknown> & { ai_explanation: AssetAiNarrative }>(`${BASE}/assets/${uuid}/impact`, { workspace: workspaceUuid })
  },

  reviewLicense(workspaceUuid: string): Promise<AssetLicenseReview> {
    return apiClient.post<AssetLicenseReview>(`${BASE}/licenses/review`, { workspace: workspaceUuid })
  },
}

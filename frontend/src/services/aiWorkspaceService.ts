import { apiClient } from './apiClient'
import type {
  AiCapabilities,
  AiConversation,
  AiCosts,
  AiActivityItem,
  AiNotificationItem,
  AiPermissionMatrix,
  AiPermissionRule,
  AiPromptTemplate,
  AiPromptTemplateInput,
  AiProvider,
  AiProviderSettingsInput,
  AiProviderTestResult,
  AiSendMessageResponse,
  AiSkillDescriptor,
  AiUsage,
  AiWorkspaceSummaryResponse,
} from '../types/aiWorkspace'

/**
 * Sprint 20 — Unified AI Workspace API client. Every call is scoped to a workspace by its UUID
 * (query string for reads/deletes, request body for writes). The backend enforces RBAC and audit;
 * the UI consumes these endpoints only and renders empty states when there is no data.
 */

const BASE = '/api/ai'

function withWorkspace(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

export const aiWorkspaceService = {
  // --- Overview ---
  getSummary(workspaceUuid: string): Promise<AiWorkspaceSummaryResponse> {
    return apiClient.get<AiWorkspaceSummaryResponse>(withWorkspace('/workspace/summary', workspaceUuid))
  },

  // --- Conversations / Chat / History ---
  listConversations(workspaceUuid: string): Promise<AiConversation[]> {
    return apiClient.get<AiConversation[]>(withWorkspace('/conversations', workspaceUuid))
  },
  createConversation(workspaceUuid: string, body: { title?: string; provider?: string }): Promise<AiConversation> {
    return apiClient.post<AiConversation>(`${BASE}/conversations`, { workspace: workspaceUuid, ...body })
  },
  getConversation(workspaceUuid: string, uuid: string): Promise<AiConversation> {
    return apiClient.get<AiConversation>(withWorkspace(`/conversations/${uuid}`, workspaceUuid))
  },
  sendMessage(
    workspaceUuid: string,
    uuid: string,
    body: { message: string; provider?: string; history?: Array<{ role: 'user' | 'assistant'; content: string }> }
  ): Promise<AiSendMessageResponse> {
    return apiClient.post<AiSendMessageResponse>(`${BASE}/conversations/${uuid}/messages`, {
      workspace: workspaceUuid,
      ...body,
    })
  },

  // --- Activity / Notifications ---
  getActivity(workspaceUuid: string): Promise<{ items: AiActivityItem[] }> {
    return apiClient.get<{ items: AiActivityItem[] }>(withWorkspace('/activity', workspaceUuid))
  },
  getNotifications(workspaceUuid: string): Promise<{ items: AiNotificationItem[] }> {
    return apiClient.get<{ items: AiNotificationItem[] }>(withWorkspace('/notifications', workspaceUuid))
  },

  // --- Usage / Costs ---
  getUsage(workspaceUuid: string): Promise<AiUsage> {
    return apiClient.get<AiUsage>(withWorkspace('/usage', workspaceUuid))
  },
  getCosts(workspaceUuid: string): Promise<AiCosts> {
    return apiClient.get<AiCosts>(withWorkspace('/costs', workspaceUuid))
  },

  // --- Skills / Capabilities ---
  getSkills(workspaceUuid: string): Promise<{ skills: AiSkillDescriptor[] }> {
    return apiClient.get<{ skills: AiSkillDescriptor[] }>(withWorkspace('/skills', workspaceUuid))
  },
  getCapabilities(workspaceUuid: string): Promise<AiCapabilities> {
    return apiClient.get<AiCapabilities>(withWorkspace('/capabilities', workspaceUuid))
  },

  // --- Prompt templates ---
  listTemplates(workspaceUuid: string): Promise<AiPromptTemplate[]> {
    return apiClient.get<AiPromptTemplate[]>(withWorkspace('/prompt-templates', workspaceUuid))
  },
  createTemplate(workspaceUuid: string, body: AiPromptTemplateInput): Promise<AiPromptTemplate> {
    return apiClient.post<AiPromptTemplate>(`${BASE}/prompt-templates`, { workspace: workspaceUuid, ...body })
  },
  updateTemplate(workspaceUuid: string, uuid: string, body: Partial<AiPromptTemplateInput>): Promise<AiPromptTemplate> {
    return apiClient.put<AiPromptTemplate>(`${BASE}/prompt-templates/${uuid}`, { workspace: workspaceUuid, ...body })
  },
  deleteTemplate(workspaceUuid: string, uuid: string): Promise<{ deleted: boolean }> {
    return apiClient.delete<{ deleted: boolean }>(withWorkspace(`/prompt-templates/${uuid}`, workspaceUuid))
  },

  // --- Providers ---
  listProviders(workspaceUuid: string): Promise<{ providers: AiProvider[] }> {
    return apiClient.get<{ providers: AiProvider[] }>(withWorkspace('/providers', workspaceUuid))
  },
  updateProviderSettings(workspaceUuid: string, uuid: string, body: AiProviderSettingsInput): Promise<AiProvider> {
    return apiClient.put<AiProvider>(`${BASE}/providers/${uuid}/settings`, { workspace: workspaceUuid, ...body })
  },
  testProvider(workspaceUuid: string, uuid: string): Promise<AiProviderTestResult> {
    return apiClient.post<AiProviderTestResult>(`${BASE}/providers/${uuid}/test`, { workspace: workspaceUuid })
  },

  // --- Permissions ---
  getPermissions(workspaceUuid: string): Promise<AiPermissionMatrix> {
    return apiClient.get<AiPermissionMatrix>(withWorkspace('/permissions', workspaceUuid))
  },
  updatePermissions(workspaceUuid: string, permissions: AiPermissionRule[]): Promise<{ roles: AiPermissionRule[] }> {
    return apiClient.put<{ roles: AiPermissionRule[] }>(`${BASE}/permissions`, { workspace: workspaceUuid, permissions })
  },
}

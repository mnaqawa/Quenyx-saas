import { apiClient } from './apiClient'
import type {
  AiAdapterActionsResponse,
  AiAdapterCapabilitiesResponse,
  AiAdapterDescriptor,
  AiAdaptersResponse,
} from '../types/aiAdapter'

/**
 * Sprint 22 — AI Adapter Platform discovery client.
 *
 * Lets the AI Workspace discover registered module adapters, their capabilities, and contextual
 * actions DYNAMICALLY. Responses are entitlement-filtered and workspace-scoped by UUID on the
 * backend. There is no per-module branching here — the client renders whatever the registry returns.
 */

const BASE = '/api/ai'

function withWorkspace(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

export const aiAdapterService = {
  listAdapters(workspaceUuid: string): Promise<AiAdaptersResponse> {
    return apiClient.get<AiAdaptersResponse>(withWorkspace('/adapters', workspaceUuid))
  },

  getAdapter(workspaceUuid: string, moduleKey: string): Promise<AiAdapterDescriptor> {
    return apiClient.get<AiAdapterDescriptor>(withWorkspace(`/adapters/${encodeURIComponent(moduleKey)}`, workspaceUuid))
  },

  capabilities(workspaceUuid: string): Promise<AiAdapterCapabilitiesResponse> {
    return apiClient.get<AiAdapterCapabilitiesResponse>(withWorkspace('/adapters/capabilities', workspaceUuid))
  },

  actions(workspaceUuid: string): Promise<AiAdapterActionsResponse> {
    return apiClient.get<AiAdapterActionsResponse>(withWorkspace('/actions', workspaceUuid))
  },
}

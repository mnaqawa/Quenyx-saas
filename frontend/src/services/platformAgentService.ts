import { apiClient } from './apiClient'

export interface PlatformAgentMetadata {
  agent_type: string
  agent_version: string
  gateway_url: string
  protocols: Record<string, { label: string; description: string; port: number | null }>
  permissions: Record<string, { label: string; required?: boolean; dangerous?: boolean; default?: boolean }>
  capabilities: Record<string, { module: string; label: string; dangerous?: boolean }>
  default_permissions: string[]
}

export interface CapabilityMatrixEntry {
  status: 'enabled' | 'available' | 'disabled_subscription' | 'disabled_permission' | 'disabled_approval'
  reason?: string
}

export interface PlatformAgentDetail {
  uuid: string
  hostname: string
  status: string
  capabilities: string[]
  enabled_modules: string[]
  permissions: string[]
  capability_matrix?: Record<string, CapabilityMatrixEntry>
  last_heartbeat?: string | null
  public_ip?: string | null
}

export const platformAgentService = {
  async getMetadata(): Promise<PlatformAgentMetadata> {
    return apiClient.get<PlatformAgentMetadata>('/api/platform/agents/metadata')
  },

  async list(workspaceId: number): Promise<PlatformAgentDetail[]> {
    const res = await apiClient.get<PlatformAgentDetail[]>(`/api/platform/agents?workspace_id=${workspaceId}`)
    return Array.isArray(res) ? res : []
  },

  async get(agentId: string): Promise<PlatformAgentDetail> {
    return apiClient.get<PlatformAgentDetail>(`/api/platform/agents/${agentId}`)
  },

  async createEnrollmentToken(
    workspaceId: number,
    options?: { name?: string; permissions?: string[]; expires_hours?: number }
  ) {
    return apiClient.post(`/api/platform/agents/enrollment-tokens`, {
      workspace_id: workspaceId,
      ...options,
    })
  },

  async updatePermissions(agentId: string, permissions: string[]) {
    return apiClient.put(`/api/platform/agents/${agentId}/permissions`, { permissions })
  },

  async revoke(agentId: string, reason?: string) {
    return apiClient.post(`/api/platform/agents/${agentId}/revoke`, reason ? { reason } : {})
  },

  async delete(agentId: string) {
    await apiClient.delete(`/api/platform/agents/${agentId}`)
  },
}

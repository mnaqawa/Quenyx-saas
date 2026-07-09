import { apiClient } from './apiClient'

export interface Agent {
  id: string
  name?: string
  hostname: string
  workspace_id?: number
  workspace_name?: string | null
  os: string | null
  arch: string | null
  agent_version: string | null
  primary_protocol: string
  enabled_protocols: string[]
  permissions: string[]
  status: string
  last_seen_at: string | null
  enrolled_at: string
}

export interface ProtocolInfo {
  label: string
  description: string
  port: number | null
  direction: string
}

export interface PermissionInfo {
  label: string
  required: boolean
}

export interface InstallInstructions {
  linux: { title: string; steps: string[] }
  windows: { title: string; steps: string[] }
  macos: { title: string; steps: string[] }
}

export interface EnrollmentTokenResponse {
  enrollment_token_id: number
  token: string
  expires_at: string | null
  primary_protocol: string
  enabled_protocols: string[]
  permissions: string[]
  gateway_url?: string
  install_instructions: InstallInstructions
  protocols: Record<string, ProtocolInfo>
  permissions_checklist: Record<string, PermissionInfo>
}

export const agentService = {
  async list(workspaceId: string | number): Promise<Agent[]> {
    const res = await apiClient.get<Agent[]>(`/api/workspaces/${workspaceId}/agents`)
    return Array.isArray(res) ? res : []
  },

  async getMetadata(workspaceId: string | number): Promise<{
    protocols: Record<string, ProtocolInfo>
    permissions: Record<string, PermissionInfo>
  }> {
    const res = await apiClient.get<{
      protocols: Record<string, ProtocolInfo>
      permissions: Record<string, PermissionInfo>
    }>(`/api/workspaces/${workspaceId}/agents/metadata`)
    return res ?? { protocols: {}, permissions: {} }
  },

  async createEnrollmentToken(
    workspaceId: string | number,
    options?: {
      name?: string
      expires_hours?: number
      target_os?: 'linux' | 'windows' | 'macos'
      allowed_hostname?: string
      primary_protocol?: string
      enabled_protocols?: string[]
      permissions?: string[]
    }
  ): Promise<EnrollmentTokenResponse> {
    const res = await apiClient.post<EnrollmentTokenResponse>(
      `/api/workspaces/${workspaceId}/agents/enrollment-token`,
      options ?? {}
    )
    if (!res) throw new Error('Invalid response')
    return res
  },

  async delete(workspaceId: string | number, agentId: string): Promise<void> {
    await apiClient.delete(`/api/workspaces/${workspaceId}/agents/${agentId}`)
  },

  async getDownloadAvailability(platform = 'linux-amd64'): Promise<{
    available: boolean
    message?: string
    size_bytes?: number
  }> {
    const res = await apiClient.get<{
      available: boolean
      message?: string
      size_bytes?: number
    }>(`/api/agents/availability/${platform}`)
    return res ?? { available: false, message: 'Unable to check agent availability' }
  },
}

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
  lifecycle_status?: string
  policy_status?: string
  policy_version?: string | null
  platform_version?: string | null
  capability_hash?: string | null
  managed_resource_count?: number
  plugin_count?: number
  capabilities: string[]
  enabled_modules: string[]
  permissions: string[]
  capability_matrix?: Record<string, CapabilityMatrixEntry>
  last_heartbeat?: string | null
  public_ip?: string | null
}

export interface FleetSummary {
  total: number
  online: number
  offline: number
  updating: number
  quarantined: number
  outdated: number
  maintenance: number
  enrollment_pending: number
  disconnected: number
  decommissioning: number
}

export interface FleetDashboard {
  fleet_summary: FleetSummary
  version_summary: Record<string, number>
  policy_summary: Record<string, number>
  gateway_summary: Array<{
    uuid: string
    name: string
    region: string | null
    health_status: string
    connected_agents: number
    endpoint_url: string
  }>
  capability_distribution: Record<string, number>
  top_errors: Array<{ agent_uuid: string; hostname: string; error: string; at?: string }>
  recent_enrollments: Array<{ agent_uuid: string; hostname: string; enrolled_at: string }>
  recent_disconnects: Array<{ agent_uuid: string; hostname: string; last_seen: string }>
  recent_upgrades: Array<{ agent_uuid: string; hostname: string; current_version: string; latest_version: string }>
  heartbeat_statistics: { total_heartbeats: number; agents_reporting: number; avg_per_agent: number }
  bandwidth_statistics: { bytes_sent: number; bytes_received: number }
  generated_at: string
}

export interface InstallerCatalog {
  config: Record<string, unknown>
  installers: Record<string, Array<Record<string, string>>>
  enroll_command: string | null
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

  async getFleet(workspaceId: number): Promise<FleetDashboard> {
    return apiClient.get<FleetDashboard>(`/api/platform/agents/fleet?workspace_id=${workspaceId}`)
  },

  async getFleetSummary(workspaceId: number) {
    return apiClient.get(`/api/platform/fleet/summary?workspace_id=${workspaceId}`)
  },

  async getHealth(workspaceId: number) {
    return apiClient.get(`/api/platform/agents/health?workspace_id=${workspaceId}`)
  },

  async getUpdates(workspaceId: number) {
    return apiClient.get(`/api/platform/agents/updates?workspace_id=${workspaceId}`)
  },

  async getConfiguration(workspaceId: number) {
    return apiClient.get(`/api/platform/agents/configuration?workspace_id=${workspaceId}`)
  },

  async getCertificates(workspaceId: number) {
    return apiClient.get(`/api/platform/agents/certificates?workspace_id=${workspaceId}`)
  },

  async getQueue(workspaceId: number) {
    return apiClient.get(`/api/platform/agents/queue?workspace_id=${workspaceId}`)
  },

  async getGateways(workspaceId: number) {
    return apiClient.get(`/api/platform/agents/gateways?workspace_id=${workspaceId}`)
  },

  async getInstallers(workspaceId: number): Promise<InstallerCatalog> {
    return apiClient.get<InstallerCatalog>(`/api/platform/agents/installers?workspace_id=${workspaceId}`)
  },

  async getPlugins(agentId: string) {
    return apiClient.get(`/api/platform/agents/${agentId}/plugins`)
  },

  async getResources(agentId: string) {
    return apiClient.get(`/api/platform/agents/${agentId}/resources`)
  },
}

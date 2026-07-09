import { apiClient } from './apiClient'

export type HostLifecycleStatus =
  | 'active'
  | 'pending'
  | 'online'
  | 'warning'
  | 'critical'
  | 'offline'
  | 'suspended'
  | 'archived'
  | 'agent_removed'
  | 'monitoring_disabled'
  | 'deleted'

export interface HostLifecycleResult {
  id: number
  uuid: string
  name: string
  lifecycle_status: HostLifecycleStatus
  lifecycle_reason?: string | null
  lifecycle_changed_at?: string | null
  enabled: boolean
  agent_id?: string | null
}

export const hostLifecycleService = {
  async disableMonitoring(workspaceId: string | number, hostUuid: string, reason?: string): Promise<HostLifecycleResult> {
    return apiClient.post<HostLifecycleResult>(
      `/api/workspaces/${workspaceId}/qynsight/hosts/${hostUuid}/disable-monitoring`,
      reason ? { reason } : {}
    )
  },

  async suspend(workspaceId: string | number, hostUuid: string, reason?: string): Promise<HostLifecycleResult> {
    return apiClient.post<HostLifecycleResult>(
      `/api/workspaces/${workspaceId}/qynsight/hosts/${hostUuid}/suspend`,
      reason ? { reason } : {}
    )
  },

  async archive(workspaceId: string | number, hostUuid: string, reason?: string): Promise<HostLifecycleResult> {
    return apiClient.post<HostLifecycleResult>(
      `/api/workspaces/${workspaceId}/qynsight/hosts/${hostUuid}/archive`,
      reason ? { reason } : {}
    )
  },

  async restore(workspaceId: string | number, hostUuid: string): Promise<HostLifecycleResult> {
    return apiClient.post<HostLifecycleResult>(
      `/api/workspaces/${workspaceId}/qynsight/hosts/${hostUuid}/restore`,
      {}
    )
  },

  async delete(workspaceId: string | number, hostUuid: string, force = false): Promise<void> {
    const q = force ? '?force=1' : ''
    await apiClient.delete(`/api/workspaces/${workspaceId}/qynsight/hosts/${hostUuid}${q}`)
  },
}

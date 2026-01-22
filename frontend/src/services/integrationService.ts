import { apiClient } from './apiClient'

export type IntegrationStatus = 'connected' | 'configured' | 'disconnected'

export interface Integration {
  id: number
  name: string
  description: string
  status: IntegrationStatus
  endpoint: string
  primary_action: string
  secondary_action: string | null
  configured?: boolean
}

export interface IntegrationConfiguration {
  api_keys: {
    github_pat: string
    slack_webhook_url: string
  }
  webhook_endpoints: {
    primary: string
    backup: string
  }
}

export const integrationService = {
  async getIntegrations(): Promise<Integration[]> {
    // apiClient unwraps { success: true, data: ... } so response is already Integration[]
    return apiClient.get<Integration[]>('/api/integrations')
  },
  async getConfiguration(): Promise<IntegrationConfiguration> {
    // apiClient unwraps { success: true, data: ... } so response is already IntegrationConfiguration
    return apiClient.get<IntegrationConfiguration>('/api/integrations/configuration')
  },
  async listProjectIntegrations(projectId: number): Promise<Integration[]> {
    // apiClient unwraps { success: true, data: ... } so response is already Integration[]
    return apiClient.get<Integration[]>(`/api/workspaces/${projectId}/integrations`)
  },
  async getProjectIntegrationConfiguration(
    projectId: number,
    integrationId: number
  ): Promise<{ settings: Record<string, unknown> | null }> {
    // apiClient unwraps { success: true, data: ... } so response is already { settings: ... }
    return apiClient.get<{ settings: Record<string, unknown> | null }>(
      `/api/workspaces/${projectId}/integrations/${integrationId}/configuration`
    )
  },
  async updateProjectIntegrationConfiguration(
    projectId: number,
    integrationId: number,
    settings: Record<string, unknown>
  ): Promise<{ settings: Record<string, unknown> | null }> {
    // apiClient unwraps { success: true, data: ... } so response is already { settings: ... }
    return apiClient.put<{ settings: Record<string, unknown> | null }>(
      `/api/workspaces/${projectId}/integrations/${integrationId}/configuration`,
      { settings }
    )
  },
}

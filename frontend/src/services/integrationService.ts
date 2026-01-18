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

interface IntegrationsResponse {
  data: Integration[]
}

interface ConfigurationResponse {
  data: IntegrationConfiguration
}

export const integrationService = {
  async getIntegrations(): Promise<Integration[]> {
    const response = await apiClient.get<IntegrationsResponse>('/api/integrations')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
  async getConfiguration(): Promise<IntegrationConfiguration> {
    const response = await apiClient.get<ConfigurationResponse>('/api/integrations/configuration')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
  async listProjectIntegrations(projectId: number): Promise<Integration[]> {
    const response = await apiClient.get<IntegrationsResponse>(`/api/projects/${projectId}/integrations`)
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
  async getProjectIntegrationConfiguration(
    projectId: number,
    integrationId: number
  ): Promise<{ settings: Record<string, unknown> | null }> {
    const response = await apiClient.get<{
      data: { settings: Record<string, unknown> | null }
    }>(`/api/projects/${projectId}/integrations/${integrationId}/configuration`)
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
  async updateProjectIntegrationConfiguration(
    projectId: number,
    integrationId: number,
    settings: Record<string, unknown>
  ): Promise<{ settings: Record<string, unknown> | null }> {
    const response = await apiClient.put<{
      data: { settings: Record<string, unknown> | null }
    }>(`/api/projects/${projectId}/integrations/${integrationId}/configuration`, {
      settings,
    })
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
}

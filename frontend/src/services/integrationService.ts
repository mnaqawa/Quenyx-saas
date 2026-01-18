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

/**
 * Normalizes response data that may be wrapped in a nested `data` property.
 */
function normalizeResponseData<T>(payload: unknown): T {
  if (Array.isArray(payload)) {
    return payload as T
  }
  
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const wrapped = payload as { data: unknown }
    if (Array.isArray(wrapped.data)) {
      return wrapped.data as T
    }
    return wrapped.data as T
  }
  
  return payload as T
}

export const integrationService = {
  async getIntegrations(): Promise<Integration[]> {
    const response = await apiClient.get<Integration[] | IntegrationsResponse>('/api/integrations')
    if (!response.success) {
      throw new Error(response.message)
    }
    return normalizeResponseData<Integration[]>(response.data)
  },
  async getConfiguration(): Promise<IntegrationConfiguration> {
    const response = await apiClient.get<IntegrationConfiguration | ConfigurationResponse>('/api/integrations/configuration')
    if (!response.success) {
      throw new Error(response.message)
    }
    return normalizeResponseData<IntegrationConfiguration>(response.data)
  },
  async listProjectIntegrations(projectId: number): Promise<Integration[]> {
    const response = await apiClient.get<Integration[] | IntegrationsResponse>(`/api/projects/${projectId}/integrations`)
    if (!response.success) {
      throw new Error(response.message || `Failed to load integrations for project ${projectId}`)
    }
    return normalizeResponseData<Integration[]>(response.data)
  },
  async getProjectIntegrationConfiguration(
    projectId: number,
    integrationId: number
  ): Promise<{ settings: Record<string, unknown> | null }> {
    const response = await apiClient.get<{
      settings: Record<string, unknown> | null
    } | {
      data: { settings: Record<string, unknown> | null }
    }>(`/api/projects/${projectId}/integrations/${integrationId}/configuration`)
    if (!response.success) {
      throw new Error(response.message || `Failed to load configuration for integration ${integrationId}`)
    }
    const normalized = normalizeResponseData<{ settings: Record<string, unknown> | null }>(response.data)
    return normalized
  },
  async updateProjectIntegrationConfiguration(
    projectId: number,
    integrationId: number,
    settings: Record<string, unknown>
  ): Promise<{ settings: Record<string, unknown> | null }> {
    const response = await apiClient.put<{
      settings: Record<string, unknown> | null
    } | {
      data: { settings: Record<string, unknown> | null }
    }>(`/api/projects/${projectId}/integrations/${integrationId}/configuration`, {
      settings,
    })
    if (!response.success) {
      throw new Error(response.message || `Failed to update configuration for integration ${integrationId}`)
    }
    const normalized = normalizeResponseData<{ settings: Record<string, unknown> | null }>(response.data)
    return normalized
  },
}

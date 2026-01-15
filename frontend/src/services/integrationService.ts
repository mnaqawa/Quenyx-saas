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
}

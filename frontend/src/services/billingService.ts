import { gatewayClient } from './gatewayClient'

export type BillingProviderType = 'manual' | 'aws' | 'azure' | 'oracle_cloud' | 'gcp' | 'custom'

export interface BillingSummary {
  current_plan: {
    key: string
    name: string
    price_cents: number | null
    interval: string | null
    status: string
  }
  workspace_usage: {
    monitored_hosts: number
    agents: number
  }
  billing_integration_status: 'not_connected' | 'configured' | 'connected'
  cost_data_sources: Array<{
    provider_type: string
    status: string
    connected_at: string | null
  }>
  billing_contact: string | null
  invoices_available: boolean
}

export interface BillingIntegration {
  id: number
  provider_type: BillingProviderType
  status: string
  config: Record<string, unknown>
  billing_contact: string | null
  connected_at: string | null
  updated_at?: string | null
}

export const billingService = {
  async getSummary(workspaceId: number): Promise<BillingSummary> {
    return gatewayClient.get<BillingSummary>(`workspaces/${workspaceId}/billing/summary`, {
      workspaceId,
      moduleKey: 'qyncore',
    })
  },

  async getIntegrations(workspaceId: number): Promise<BillingIntegration[]> {
    return gatewayClient.get<BillingIntegration[]>(`workspaces/${workspaceId}/billing/integrations`, {
      workspaceId,
      moduleKey: 'qyncore',
    })
  },

  async saveIntegration(
    workspaceId: number,
    payload: {
      provider_type: BillingProviderType
      status?: string
      config?: Record<string, unknown>
      billing_contact?: string
    },
  ): Promise<BillingIntegration> {
    return gatewayClient.post<BillingIntegration>(
      `workspaces/${workspaceId}/billing/integrations`,
      payload,
      { workspaceId, moduleKey: 'qyncore' },
    )
  },
}

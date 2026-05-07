import { apiClient } from './apiClient'
import { PlanKey, ProjectEntitlements, ProjectSubscription } from '../types/subscription'

export interface Plan {
  key: PlanKey
  name: string
  price_cents: number
  interval: string | null
  features: {
    modules_allowed: string[]
    modules?: string[] // Legacy support
    limits: Record<string, unknown>
  }
}

interface SubscriptionResponse {
  plan: {
    key: string
    name: string
    price_cents?: number
    interval?: string | null
  }
  status: string
  starts_at?: string | null
  ends_at?: string | null
}

export const subscriptionService = {
  async getProjectSubscription(projectId: number): Promise<ProjectSubscription> {
    const response = await apiClient.get<SubscriptionResponse>(
      `/api/workspaces/${projectId}/subscription`
    )
    // Transform backend response to frontend type
    return {
      status: response.status as ProjectSubscription['status'],
      plan: {
        key: response.plan.key as PlanKey,
        name: response.plan.name,
      },
      starts_at: response.starts_at,
      ends_at: response.ends_at,
    }
  },

  async updateProjectSubscription(
    projectId: number,
    planKey: PlanKey
  ): Promise<ProjectSubscription> {
    const response = await apiClient.put<SubscriptionResponse>(
      `/api/workspaces/${projectId}/subscription`,
      { plan_key: planKey }
    )
    // Transform backend response to frontend type
    return {
      status: response.status as ProjectSubscription['status'],
      plan: {
        key: response.plan.key as PlanKey,
        name: response.plan.name,
      },
      starts_at: response.starts_at,
      ends_at: response.ends_at,
    }
  },

  async getProjectEntitlements(projectId: number): Promise<ProjectEntitlements> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectEntitlements
    return apiClient.get<ProjectEntitlements>(`/api/workspaces/${projectId}/entitlements`)
  },

  /**
   * Get all plans catalog
   */
  async getPlans(): Promise<Plan[]> {
    // apiClient unwraps { success: true, data: ... } so response is already Plan[]
    return apiClient.get<Plan[]>('/api/plans')
  },
}

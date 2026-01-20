import { apiClient, ApiResponse } from './apiClient'
import { PlanKey, ProjectEntitlements, ProjectSubscription } from '../types/subscription'

export interface Plan {
  key: PlanKey
  name: string
  price_cents: number
  interval: string | null
  features: {
    modules_allowed: string[]
    modules?: string[] // Legacy support
    limits: Record<string, any>
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
  async getProjectSubscription(projectId: number): Promise<ApiResponse<ProjectSubscription>> {
    const response = await apiClient.get<SubscriptionResponse>(
      `/api/projects/${projectId}/subscription`
    )
    if (!response.success) {
      return response
    }
    return {
      success: true,
      data: {
        status: response.data.status as ProjectSubscription['status'],
        plan: {
          key: response.data.plan.key as PlanKey,
          name: response.data.plan.name,
        },
        starts_at: response.data.starts_at,
        ends_at: response.data.ends_at,
      },
    }
  },

  async updateProjectSubscription(
    projectId: number,
    planKey: PlanKey
  ): Promise<ApiResponse<ProjectSubscription>> {
    const response = await apiClient.put<SubscriptionResponse>(
      `/api/projects/${projectId}/subscription`,
      { plan_key: planKey }
    )
    if (!response.success) {
      return response
    }
    return {
      success: true,
      data: {
        status: response.data.status as ProjectSubscription['status'],
        plan: {
          key: response.data.plan.key as PlanKey,
          name: response.data.plan.name,
        },
        starts_at: response.data.starts_at,
        ends_at: response.data.ends_at,
      },
    }
  },

  async getProjectEntitlements(projectId: number): Promise<ApiResponse<ProjectEntitlements>> {
    const response = await apiClient.get<ProjectEntitlements>(
      `/api/projects/${projectId}/entitlements`
    )
    if (!response.success) {
      return response
    }
    return response
  },

  /**
   * Get all plans catalog
   */
  async getPlans(): Promise<ApiResponse<Plan[]>> {
    const response = await apiClient.get<Plan[] | { data: Plan[] }>('/api/plans')
    if (!response.success) {
      return response
    }
    // Normalize response
    const data = Array.isArray(response.data) ? response.data : (response.data as { data: Plan[] }).data
    return {
      success: true,
      data,
    }
  },
}

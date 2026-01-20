import { apiClient, ApiResponse } from './apiClient'

export interface Plan {
  id?: number
  key: string
  name: string
  price_cents: number
  interval: string | null
  features: {
    modules_allowed: string[]
    modules?: string[] // Legacy support
    limits?: Record<string, any>
  }
}

export const planService = {
  async getPlans(): Promise<ApiResponse<Plan[]>> {
    const response = await apiClient.get<Plan[] | { data: Plan[] }>('/api/plans')
    if (!response.success) {
      return response
    }
    const data = Array.isArray(response.data)
      ? response.data
      : (response.data as { data: Plan[] }).data
    return {
      success: true,
      data,
    }
  },

  async createPlan(plan: Omit<Plan, 'id'>): Promise<ApiResponse<Plan>> {
    const response = await apiClient.post<Plan | { data: Plan }>('/api/plans', plan)
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as Plan
    return {
      success: true,
      data,
    }
  },

  async updatePlan(planId: number, plan: Partial<Omit<Plan, 'id' | 'key'>>): Promise<ApiResponse<Plan>> {
    const response = await apiClient.put<Plan | { data: Plan }>(`/api/plans/${planId}`, plan)
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as Plan
    return {
      success: true,
      data,
    }
  },

  async deletePlan(planId: number): Promise<ApiResponse<void>> {
    const response = await apiClient.delete(`/api/plans/${planId}`)
    if (!response.success) {
      return response as ApiResponse<void>
    }
    return {
      success: true,
      data: undefined,
    }
  },
}

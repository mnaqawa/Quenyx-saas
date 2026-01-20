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
    const response = await apiClient.get<{ success: boolean; data: Plan[] }>('/api/plans')
    if (!response.success) {
      return response
    }
    // Backend returns { success: true, data: [...] }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data: Array.isArray(data) ? data : [],
    }
  },
}

import { apiClient } from './apiClient'

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
  async getPlans(): Promise<Plan[]> {
    // apiClient unwraps { success: true, data: ... } so response is already Plan[]
    return apiClient.get<Plan[]>('/api/plans')
  },
}

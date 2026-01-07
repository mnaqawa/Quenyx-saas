import { apiClient } from './apiClient'

export interface Module {
  id: number
  name: string
  description: string | null
  status: 'active' | 'inactive' | 'maintenance'
  subscription_state: 'active' | 'inactive' | 'trial' | 'expired'
}

export interface DashboardData {
  platform_health: string
  modules: Module[]
}

export const dashboardService = {
  async getDashboard(): Promise<DashboardData> {
    const response = await apiClient.get<DashboardData>('/api/dashboard')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data
  },
}
import { apiClient } from './apiClient'

export interface Module {
  id: number
  name: string
  description: string | null
  status: 'active' | 'inactive' | 'maintenance'
  subscription_state: 'active' | 'inactive' | 'trial' | 'expired'
}

export interface PerformanceSeries {
  label: string
  values: number[]
}

export interface AlertsByModule {
  label: string
  primary: number
  secondary: number
}

export interface DashboardData {
  platform_health: string
  modules: Module[]
  performance_series: PerformanceSeries[]
  weekly_uptime: number[]
  alerts_by_module: AlertsByModule[]
}

interface DashboardResponse {
  data: DashboardData
}

export const dashboardService = {
  async getDashboard(): Promise<DashboardData> {
    const response = await apiClient.get<DashboardResponse>('/api/dashboard')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
}
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

export const dashboardService = {
  async getDashboard(): Promise<DashboardData> {
    // apiClient unwraps { success: true, data: ... } so response is already DashboardData
    return apiClient.get<DashboardData>('/api/dashboard')
  },
}
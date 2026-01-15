import { apiClient } from './apiClient'
import type { Module } from './dashboardService'

interface ModulesResponse {
  data: Module[]
}

export const moduleService = {
  async getModules(): Promise<Module[]> {
    const response = await apiClient.get<ModulesResponse>('/api/modules')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
}

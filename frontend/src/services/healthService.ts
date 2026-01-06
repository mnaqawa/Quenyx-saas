import { apiClient } from './apiClient'

export interface HealthResponse {
  status: string
}

export const healthService = {
  async check(): Promise<HealthResponse> {
    const response = await apiClient.get<HealthResponse>('/api/health')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data
  },
}

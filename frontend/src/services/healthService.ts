import { apiClient } from './apiClient'

export interface HealthResponse {
  status: string
}

interface HealthEnvelope {
  data: HealthResponse
}

export const healthService = {
  async check(): Promise<HealthResponse> {
    const response = await apiClient.get<HealthEnvelope>('/api/health')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
}

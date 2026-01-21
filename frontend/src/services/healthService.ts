import { apiClient } from './apiClient'

export interface HealthResponse {
  status: string
}

export const healthService = {
  async check(): Promise<HealthResponse> {
    // apiClient unwraps { success: true, data: ... } so response is already HealthResponse
    return apiClient.get<HealthResponse>('/api/health')
  },
}

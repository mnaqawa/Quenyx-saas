import { apiClient } from './apiClient'

export interface ProfileStats {
  api_calls_30d: number
  last_login_at: string | null
  created_at: string | null
}

interface ProfileStatsResponse {
  data: ProfileStats
}

export const profileService = {
  async getStats(): Promise<ProfileStats> {
    const response = await apiClient.get<ProfileStatsResponse>('/api/profile/stats')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },
}

import { apiClient, ApiResponse } from './apiClient'

export interface UserProfile {
  id: number
  name: string
  email: string
}

export const profileService = {
  async getProfile(): Promise<ApiResponse<UserProfile>> {
    const response = await apiClient.get<{ success: boolean; data: UserProfile }>('/api/me')
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data,
    }
  },

  async updateProfile(updates: { name: string }): Promise<ApiResponse<UserProfile>> {
    const response = await apiClient.put<{ success: boolean; data: UserProfile }>('/api/me', updates)
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data,
    }
  },
}

import { apiClient, ApiResponse } from './apiClient'

export interface ProfileStats {
  api_calls_30d: number
  last_login_at: string | null
  created_at: string | null
}

export interface UserProfile {
  id: number
  name: string
  email: string
  role: string
  preferences: Record<string, any>
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

  async getProfile(): Promise<ApiResponse<UserProfile>> {
    const response = await apiClient.get<UserProfile | { data: UserProfile }>('/api/profile')
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as UserProfile
    return {
      success: true,
      data,
    }
  },

  async updateProfile(updates: { name?: string; preferences?: Record<string, any> }): Promise<ApiResponse<UserProfile>> {
    const response = await apiClient.put<UserProfile | { data: UserProfile }>('/api/profile', updates)
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as UserProfile
    return {
      success: true,
      data,
    }
  },

  async updatePassword(currentPassword: string, newPassword: string): Promise<ApiResponse<void>> {
    const response = await apiClient.put('/api/profile/password', {
      current_password: currentPassword,
      new_password: newPassword,
    })
    if (!response.success) {
      return response as ApiResponse<void>
    }
    return {
      success: true,
      data: undefined,
    }
  },
}

import { apiClient, ApiResponse } from './apiClient'

export interface UserProfile {
  id: number
  name: string
  email: string
}

export const profileService = {
  async getProfile(): Promise<ApiResponse<UserProfile>> {
    const response = await apiClient.get<UserProfile>('/api/auth/me')
    if (!response.success) {
      return response
    }
    // apiClient unwraps { success: true, data: ... } so response.data is already UserProfile
    return {
      success: true,
      data: response.data,
    }
  },

  async updateProfile(updates: { name: string }): Promise<ApiResponse<UserProfile>> {
    const response = await apiClient.put<UserProfile>('/api/auth/me', updates)
    if (!response.success) {
      return response
    }
    // apiClient unwraps { success: true, data: ... } so response.data is already UserProfile
    return {
      success: true,
      data: response.data,
    }
  },
}

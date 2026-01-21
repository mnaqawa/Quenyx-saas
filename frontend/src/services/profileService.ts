import { apiClient } from './apiClient'

export interface UserProfile {
  id: number
  name: string
  email: string
}

export const profileService = {
  async getProfile(): Promise<UserProfile> {
    // apiClient unwraps { success: true, data: ... } so response is already UserProfile
    return apiClient.get<UserProfile>('/api/auth/me')
  },

  async updateProfile(updates: { name: string }): Promise<UserProfile> {
    // apiClient unwraps { success: true, data: ... } so response is already UserProfile
    return apiClient.put<UserProfile>('/api/auth/me', updates)
  },
}

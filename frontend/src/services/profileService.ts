import { apiClient } from './apiClient'

export interface UserProfileStats {
  active_modules: number
  integrations: number
  api_calls_30d: number
}

export interface UserProfilePreferences {
  theme?: 'light' | 'dark' | 'system'
  language?: 'en' | 'ar'
  notifications?: Record<string, boolean>
}

export interface UserProfile {
  id: number
  name: string
  email: string
  created_at?: string | null
  last_login_at?: string | null
  preferences?: UserProfilePreferences
  stats?: UserProfileStats
}

export const profileService = {
  async getProfile(): Promise<UserProfile> {
    return apiClient.get<UserProfile>('/api/auth/me')
  },

  async updateProfile(updates: { name?: string; preferences?: UserProfilePreferences }): Promise<UserProfile> {
    return apiClient.put<UserProfile>('/api/auth/me', updates)
  },

  async changePassword(currentPassword: string, newPassword: string): Promise<{ message: string }> {
    const res = await apiClient.put<{ message: string }>('/api/auth/me/password', {
      current_password: currentPassword,
      password: newPassword,
      password_confirmation: newPassword,
    })
    return res
  },
}

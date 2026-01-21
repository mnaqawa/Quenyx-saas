import { apiClient, clearAuthToken, setAuthToken } from './apiClient'

export interface AuthUser {
  id: number
  name: string
  email: string
  last_login_at?: string | null
  api_calls_30d?: number
  created_at?: string | null
}

interface LoginResponse {
  data: {
    token: string
    user: AuthUser
  }
}

export const authService = {
  async login(email: string, password: string): Promise<LoginResponse['data']> {
    const response = await apiClient.post<LoginResponse>('/api/auth/login', {
      email,
      password,
    })

    // apiClient now returns unwrapped data directly
    setAuthToken(response.data.token)
    return response.data
  },

  async me(): Promise<AuthUser> {
    // Backend returns { success: true, data: { id, name, email } }
    // apiClient unwraps it, so response is already AuthUser
    return apiClient.get<AuthUser>('/api/auth/me')
  },

  async logout(): Promise<void> {
    await apiClient.post('/api/auth/logout')
    clearAuthToken()
  },
}

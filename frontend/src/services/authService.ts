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

interface MeResponse {
  data: {
    user: AuthUser
  }
}

export const authService = {
  async login(email: string, password: string): Promise<LoginResponse['data']> {
    const response = await apiClient.post<LoginResponse>('/api/auth/login', {
      email,
      password,
    })

    if (!response.success) {
      throw new Error(response.message)
    }

    setAuthToken(response.data.data.token)
    return response.data.data
  },

  async me(): Promise<AuthUser> {
    const response = await apiClient.get<MeResponse>('/api/auth/me')
    if (!response.success) {
      throw new Error(response.message)
    }

    return response.data.data.user
  },

  async logout(): Promise<void> {
    const response = await apiClient.post('/api/auth/logout')
    if (!response.success) {
      throw new Error(response.message)
    }
    clearAuthToken()
  },
}

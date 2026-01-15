import { apiClient, clearAuthToken, setAuthToken } from './apiClient'

export interface AuthUser {
  id: number
  name: string
  email: string
}

interface LoginResponse {
  token: string
  user: AuthUser
}

interface MeResponse {
  user: AuthUser
}

export const authService = {
  async login(email: string, password: string): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>('/api/auth/login', {
      email,
      password,
    })

    if (!response.success) {
      throw new Error(response.message)
    }

    setAuthToken(response.data.token)
    return response.data
  },

  async me(): Promise<AuthUser> {
    const response = await apiClient.get<MeResponse>('/api/auth/me')
    if (!response.success) {
      throw new Error(response.message)
    }

    return response.data.user
  },

  async logout(): Promise<void> {
    const response = await apiClient.post('/api/auth/logout')
    if (!response.success) {
      throw new Error(response.message)
    }
    clearAuthToken()
  },
}

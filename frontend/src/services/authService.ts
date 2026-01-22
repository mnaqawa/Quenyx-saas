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
  token: string
  user: AuthUser
}

interface RegisterResponse {
  token: string
  user: AuthUser
  workspace: {
    id: number
    name: string
  }
}

export const authService = {
  async login(email: string, password: string): Promise<LoginResponse> {
    // Backend returns { success: true, data: { token, user } }
    // apiClient unwraps it, so response is already { token, user }
    const response = await apiClient.post<LoginResponse>('/api/auth/login', {
      email,
      password,
    })

    setAuthToken(response.token)
    return response
  },

  async register(
    name: string,
    email: string,
    password: string,
    workspaceName?: string
  ): Promise<RegisterResponse> {
    // Backend returns { success: true, data: { token, user, workspace } }
    // apiClient unwraps it, so response is already { token, user, workspace }
    const response = await apiClient.post<RegisterResponse>('/api/auth/register', {
      name,
      email,
      password,
      ...(workspaceName && { workspace_name: workspaceName }),
    })

    setAuthToken(response.token)
    return response
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

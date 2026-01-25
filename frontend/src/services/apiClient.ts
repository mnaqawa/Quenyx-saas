const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
const TOKEN_STORAGE_KEY = 'portshield.auth.token'

export const getAuthToken = (): string | null => {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export const setAuthToken = (token: string): void => {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export const clearAuthToken = (): void => {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

// Legacy types for backward compatibility during migration
export interface ApiError {
  success: false
  message: string
  errors?: Record<string, string[]> | null
}

export interface ApiSuccess<T> {
  success: true
  data: T
}

export type ApiResponse<T> = ApiSuccess<T> | ApiError

class ApiClient {
  private baseUrl: string

  constructor(baseUrl: string) {
    this.baseUrl = baseUrl
  }

  private async request<T>(
    endpoint: string,
    options: RequestInit = {}
  ): Promise<T> {
    const url = `${this.baseUrl}${endpoint}`
    
    const defaultHeaders: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    }

    const mergedHeaders = {
      ...defaultHeaders,
      ...(options.headers as Record<string, string> | undefined),
    }

    const token = getAuthToken()
    if (token && !mergedHeaders.Authorization) {
      mergedHeaders.Authorization = `Bearer ${token}`
    }

    const config: RequestInit = {
      headers: mergedHeaders,
      ...options,
    }

    try {
      const response = await fetch(url, config)
      const data = await response.json()

      // Strict envelope handling: backend MUST return { success, data } or { success, message }
      if (!data || typeof data !== 'object' || !('success' in data)) {
        throw new Error('Invalid API response: missing success field')
      }

      if (data.success === false) {
        // Handle error responses
        if (response.status === 401) {
          clearAuthToken()
        }
        const message = data.message || 'An error occurred'
        const error = new Error(message)
        ;(error as any).errors = data.errors || null
        ;(error as any).status = response.status
        throw error
      }

      if (data.success === true) {
        // Success response: must have data field
        if (!('data' in data)) {
          throw new Error('Invalid API response: success=true but missing data field')
        }
        return data.data as T
      }

      // Invalid success value
      throw new Error(`Invalid API response: success=${data.success}`)
    } catch (error) {
      // Re-throw if it's already an Error we created
      if (error instanceof Error) {
        throw error
      }
      // Otherwise wrap network/parsing errors
      throw new Error(error instanceof Error ? error.message : 'Network error')
    }
  }

  async get<T>(endpoint: string, headers?: Record<string, string>): Promise<T> {
    return this.request<T>(endpoint, { method: 'GET', headers })
  }

  async post<T>(endpoint: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
    return this.request<T>(endpoint, {
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
      headers,
    })
  }

  async put<T>(endpoint: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
    return this.request<T>(endpoint, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
      headers,
    })
  }

  async delete<T>(endpoint: string, headers?: Record<string, string>): Promise<T> {
    return this.request<T>(endpoint, { method: 'DELETE', headers })
  }
}

export const apiClient = new ApiClient(API_BASE_URL)

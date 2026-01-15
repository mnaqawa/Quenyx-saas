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
  ): Promise<ApiResponse<T>> {
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

      if (!response.ok) {
        if (response.status === 401) {
          clearAuthToken()
        }
        // Handle Laravel error format
        if (data.success === false) {
          return {
            success: false,
            message: data.message || 'An error occurred',
            errors: data.errors || null,
          }
        }
        return {
          success: false,
          message: data.message || 'An error occurred',
          errors: data.errors || null,
        }
      }

      // Handle success responses (may or may not have success field)
      return {
        success: true,
        data: data as T,
      }
    } catch (error) {
      return {
        success: false,
        message: error instanceof Error ? error.message : 'Network error',
        errors: null,
      }
    }
  }

  async get<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { method: 'GET' })
  }

  async post<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
    })
  }

  async put<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    })
  }

  async delete<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { method: 'DELETE' })
  }
}

export const apiClient = new ApiClient(API_BASE_URL)

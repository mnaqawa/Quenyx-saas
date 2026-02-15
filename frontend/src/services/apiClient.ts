const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
const TOKEN_STORAGE_KEY = 'portshield.auth.token'
const WORKSPACE_STORAGE_KEY = 'portshield.selected_workspace_id'
const OLD_WORKSPACE_STORAGE_KEY = 'portshield.selected_project_id'

/** Emitted when a workspace-scoped API returns 404 (workspace deleted or invalid) */
export const WORKSPACE_404_EVENT = 'portshield:workspace-404'

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
      ...options,
      headers: mergedHeaders,
    }

    try {
      const response = await fetch(url, config)
      
      // Log request details for debugging (only in development)
      if (import.meta.env.DEV) {
        console.log('API Request:', {
          url,
          method: options.method || 'GET',
          status: response.status,
          statusText: response.statusText,
          body: options.body,
          headers: mergedHeaders,
        })
      }
      
      // Check if response is ok (status 200-299)
      if (!response.ok) {
        // Try to parse error response
        let errorMessage = `HTTP ${response.status}: ${response.statusText}`
        const contentType = response.headers.get('content-type')
        
        if (contentType && contentType.includes('application/json')) {
          try {
            const errorData = await response.json()
            if (errorData && typeof errorData === 'object') {
              if (errorData.message) {
                errorMessage = errorData.message
              } else if (errorData.error) {
                errorMessage = errorData.error
              }
            }
          } catch (parseError) {
            // If JSON parsing fails, use status text
            console.warn('Failed to parse error response as JSON:', parseError)
          }
        }
        
        const error = new Error(`${errorMessage} (${url})`)
        ;(error as any).status = response.status
        ;(error as any).url = url
        
        // Standardized error mapping for gateway client
        if (response.status === 401) {
          clearAuthToken()
          const authError = new Error('Unauthorized')
          ;(authError as any).status = 401
          ;(authError as any).isAuthError = true
          ;(authError as any).url = url
          ;(authError as any).userMessage = 'Unauthorized'
          if (import.meta.env.DEV) {
            console.error('API Error (401):', { url, status: response.status, message: errorMessage })
          }
          throw authError
        }
        
        if (response.status === 403) {
          // Use "Locked" only when backend explicitly indicates entitlement/module lock
          const isEntitlementLock = /locked|entitlement|module\s*access/i.test(errorMessage)
          const msg = isEntitlementLock ? 'Locked' : 'Access denied'
          const forbiddenError = new Error(msg)
          ;(forbiddenError as any).status = 403
          ;(forbiddenError as any).url = url
          ;(forbiddenError as any).userMessage = msg
          ;(forbiddenError as any).isEntitlementLock = isEntitlementLock
          if (import.meta.env.DEV) {
            console.error('API Error (403):', { url, status: response.status, message: errorMessage })
          }
          throw forbiddenError
        }

        // 404 on workspace-scoped endpoints: workspace may have been deleted
        if (response.status === 404) {
          const workspaceMatch = url.match(/\/workspaces\/(\d+)\//) || url.match(/\/projects\/(\d+)\//)
          if (workspaceMatch) {
            const workspaceId = workspaceMatch[1]
            const stored = localStorage.getItem(WORKSPACE_STORAGE_KEY) || localStorage.getItem(OLD_WORKSPACE_STORAGE_KEY)
            if (stored === workspaceId) {
              localStorage.removeItem(WORKSPACE_STORAGE_KEY)
              localStorage.removeItem(OLD_WORKSPACE_STORAGE_KEY)
              window.dispatchEvent(new CustomEvent(WORKSPACE_404_EVENT, { detail: { workspaceId } }))
            }
          }
        }
        
        if (response.status >= 500) {
          const serverError = new Error('Service unavailable')
          ;(serverError as any).status = response.status
          ;(serverError as any).url = url
          ;(serverError as any).userMessage = 'Service unavailable'
          if (import.meta.env.DEV) {
            console.error('API Error (5xx):', { url, status: response.status, message: errorMessage })
          }
          throw serverError
        }
        
        // Log error details in development
        if (import.meta.env.DEV) {
          console.error('API Error:', {
            url,
            status: response.status,
            statusText: response.statusText,
            message: errorMessage,
          })
        }
        
        throw error
      }

      // Parse JSON response
      let data
      try {
        const text = await response.text()
        if (!text) {
          throw new Error('Empty response from server')
        }
        data = JSON.parse(text)
      } catch (parseError) {
        throw new Error(`Failed to parse response: ${parseError instanceof Error ? parseError.message : 'Invalid JSON'}`)
      }

      // Strict envelope handling: backend MUST return { success, data } or { success, message }
      if (!data || typeof data !== 'object' || !('success' in data)) {
        throw new Error('Invalid API response: missing success field')
      }

      if (data.success === false) {
        // Handle error responses
        if (response.status === 401) {
          clearAuthToken()
          // For 401, throw AuthenticationException-like error
          const error = new Error(data.message || 'Unauthenticated')
          ;(error as any).status = 401
          ;(error as any).isAuthError = true
          ;(error as any).url = url
          throw error
        }
        
        // Extract error message - prioritize message field, fallback to error field
        let message = data.message || data.error || `Server error (${response.status})`
        
        // For validation errors (422), include field-specific errors in message
        if (response.status === 422 && data.errors) {
          const errorFields = Object.keys(data.errors)
          const errorMessages = errorFields.map(field => {
            const fieldErrors = Array.isArray(data.errors[field]) ? data.errors[field] : [data.errors[field]]
            return `${field}: ${fieldErrors.join(', ')}`
          })
          if (errorMessages.length > 0) {
            message = errorMessages.join('. ')
          }
        }
        
        const error = new Error(message)
        ;(error as any).errors = data.errors || null
        ;(error as any).status = response.status
        ;(error as any).url = url
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
        // Add more context for network errors
        if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError') || error.message.includes('Network request failed')) {
          const networkError = new Error(`Network error - cannot connect to ${url}. Please check your connection and ensure the server is running.`)
          ;(networkError as any).url = url
          ;(networkError as any).originalError = error
          throw networkError
        }
        // Preserve URL in error if not already present
        if (!(error as any).url) {
          ;(error as any).url = url
        }
        throw error
      }
      // Handle timeout errors
      if (error instanceof Error && (error.message.includes('timeout') || error.message.includes('aborted'))) {
        const timeoutError = new Error('Gateway timeout')
        ;(timeoutError as any).status = 504
        ;(timeoutError as any).url = url
        ;(timeoutError as any).userMessage = 'Request timed out'
        throw timeoutError
      }
      
      // Otherwise wrap network/parsing errors
      const wrappedError = new Error(error instanceof Error ? error.message : 'Network error')
      ;(wrappedError as any).url = url
      ;(wrappedError as any).userMessage = 'Network error - please check your connection'
      throw wrappedError
    }
  }

  async get<T>(endpoint: string, headers?: Record<string, string>): Promise<T> {
    return this.request<T>(endpoint, { method: 'GET', headers })
  }

  async post<T>(endpoint: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
    // Log request body in development
    if (import.meta.env.DEV && body) {
      console.log('POST Request Body:', body)
    }
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

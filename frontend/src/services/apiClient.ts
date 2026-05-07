import type { RequestError } from '../lib/requestError'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
const TOKEN_STORAGE_KEY = 'quenyx.auth.token'
const WORKSPACE_STORAGE_KEY = 'quenyx.selected_workspace_id'

/** Emitted when a workspace-scoped API returns 404 (workspace deleted or invalid) */
export const WORKSPACE_404_EVENT = 'quenyx:workspace-404'

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

type ErrorJson = {
  message?: string
  error?: string
  errors?: Record<string, unknown>
}

type SuccessEnvelope = { success: true; data: unknown }
type FailureEnvelope = { success: false; message?: string; error?: string; errors?: unknown }

function isRecord(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v)
}

class ApiClient {
  private baseUrl: string

  constructor(baseUrl: string) {
    this.baseUrl = baseUrl
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const url = `${this.baseUrl}${endpoint}`

    const defaultHeaders: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
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

      if (!response.ok) {
        let errorMessage = `HTTP ${response.status}: ${response.statusText}`
        const contentType = response.headers.get('content-type')
        let errorData: ErrorJson | null = null

        if (contentType && contentType.includes('application/json')) {
          try {
            const json: unknown = await response.json()
            if (isRecord(json)) {
              errorData = json as ErrorJson
              if (typeof json.message === 'string') {
                errorMessage = json.message
              } else if (typeof json.error === 'string') {
                errorMessage = json.error
              }
            }
          } catch (parseError) {
            console.warn('Failed to parse error response as JSON:', parseError)
          }
        }

        if (response.status === 422 && errorData?.errors && isRecord(errorData.errors)) {
          const errors = errorData.errors
          const parts: string[] = []
          for (const [field, val] of Object.entries(errors)) {
            const msgs = Array.isArray(val) ? val : [val]
            const cleaned = msgs
              .map((m) => (typeof m === 'string' ? m : 'Invalid value'))
              .filter(Boolean)
            if (cleaned.length > 0) {
              parts.push(`${field}: ${cleaned.join(', ')}`)
            }
          }
          if (parts.length > 0) {
            errorMessage = parts.join('. ')
          }
        }

        const err = new Error(errorMessage) as RequestError
        err.status = response.status
        err.url = url
        if (errorData?.errors && isRecord(errorData.errors)) {
          err.errors = errorData.errors
        }

        if (response.status === 401) {
          clearAuthToken()
          const authError = new Error('Unauthorized') as RequestError
          authError.status = 401
          authError.isAuthError = true
          authError.url = url
          authError.userMessage = 'Unauthorized'
          if (import.meta.env.DEV) {
            console.error('API Error (401):', { url, status: response.status, message: errorMessage })
          }
          throw authError
        }

        if (response.status === 403) {
          const isEntitlementLock = /locked|entitlement|module\s*access/i.test(errorMessage)
          const msg = isEntitlementLock ? 'Locked' : 'Access denied'
          const forbiddenError = new Error(msg) as RequestError
          forbiddenError.status = 403
          forbiddenError.url = url
          forbiddenError.userMessage = msg
          forbiddenError.isEntitlementLock = isEntitlementLock
          if (import.meta.env.DEV) {
            console.error('API Error (403):', { url, status: response.status, message: errorMessage })
          }
          throw forbiddenError
        }

        if (response.status === 404) {
          const workspaceMatch = url.match(/\/workspaces\/(\d+)\//) || url.match(/\/projects\/(\d+)\//)
          if (workspaceMatch) {
            const workspaceId = workspaceMatch[1]
            const stored = localStorage.getItem(WORKSPACE_STORAGE_KEY)
            if (stored === workspaceId) {
              localStorage.removeItem(WORKSPACE_STORAGE_KEY)
              window.dispatchEvent(new CustomEvent(WORKSPACE_404_EVENT, { detail: { workspaceId } }))
            }
          }
        }

        if (response.status >= 500) {
          const serverError = new Error('Service unavailable') as RequestError
          serverError.status = response.status
          serverError.url = url
          serverError.userMessage = 'Service unavailable'
          if (import.meta.env.DEV) {
            console.error('API Error (5xx):', { url, status: response.status, message: errorMessage })
          }
          throw serverError
        }

        if (import.meta.env.DEV) {
          console.error('API Error:', {
            url,
            status: response.status,
            statusText: response.statusText,
            message: errorMessage,
          })
        }

        throw err
      }

      let data: unknown
      try {
        const text = await response.text()
        if (!text) {
          throw new Error('Empty response from server')
        }
        data = JSON.parse(text) as unknown
      } catch (parseError) {
        throw new Error(`Failed to parse response: ${parseError instanceof Error ? parseError.message : 'Invalid JSON'}`)
      }

      if (!isRecord(data) || !('success' in data)) {
        throw new Error('Invalid API response: missing success field')
      }

      if (data.success === false) {
        const fail = data as FailureEnvelope
        if (response.status === 401) {
          clearAuthToken()
          const e401 = new Error(
            typeof fail.message === 'string' ? fail.message : 'Unauthenticated'
          ) as RequestError
          e401.status = 401
          e401.isAuthError = true
          e401.url = url
          throw e401
        }

        let message =
          (typeof fail.message === 'string' && fail.message) ||
          (typeof fail.error === 'string' && fail.error) ||
          `Server error (${response.status})`

        if (response.status === 422 && fail.errors && isRecord(fail.errors)) {
          const fe = fail.errors
          const errorFields = Object.keys(fe)
          const errorMessages = errorFields.map((field) => {
            const v = fe[field]
            const fieldErrors = Array.isArray(v) ? v : [v]
            return `${field}: ${fieldErrors.map((x) => String(x)).join(', ')}`
          })
          if (errorMessages.length > 0) {
            message = errorMessages.join('. ')
          }
        }

        const e = new Error(message) as RequestError
        e.errors = fail.errors ?? null
        e.status = response.status
        e.url = url
        throw e
      }

      if (data.success === true) {
        const ok = data as SuccessEnvelope
        if (!('data' in data)) {
          throw new Error('Invalid API response: success=true but missing data field')
        }
        return ok.data as T
      }

      throw new Error(`Invalid API response: success=${String((data as { success?: unknown }).success)}`)
    } catch (raw: unknown) {
      if (raw instanceof Error) {
        if (
          raw.message.includes('Failed to fetch') ||
          raw.message.includes('NetworkError') ||
          raw.message.includes('Network request failed')
        ) {
          const networkError = new Error(
            `Network error - cannot connect to ${url}. Please check your connection and ensure the server is running.`
          ) as RequestError
          networkError.url = url
          networkError.originalError = raw
          throw networkError
        }
        if (raw.message.includes('timeout') || raw.message.includes('aborted')) {
          const timeoutError = new Error('Gateway timeout') as RequestError
          timeoutError.status = 504
          timeoutError.url = url
          timeoutError.userMessage = 'Request timed out'
          throw timeoutError
        }
        const re = raw as RequestError
        if (!re.url) {
          re.url = url
        }
        throw raw
      }

      const wrappedError = new Error(
        typeof raw === 'string' ? raw : 'Network error'
      ) as RequestError
      wrappedError.url = url
      wrappedError.userMessage = 'Network error - please check your connection'
      throw wrappedError
    }
  }

  async get<T>(endpoint: string, headers?: Record<string, string>): Promise<T> {
    return this.request<T>(endpoint, { method: 'GET', headers })
  }

  async post<T>(endpoint: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
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

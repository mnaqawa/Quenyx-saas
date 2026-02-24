// Gateway client - single source of truth for all API calls through the gateway middleware
// This sits between frontend and backend, and between platform and engines

import { apiClient } from './apiClient'

// Gateway configuration
// GATEWAY_BASE_URL reserved for future use: import.meta.env.VITE_GATEWAY_BASE_URL
const USE_GATEWAY = import.meta.env.VITE_USE_GATEWAY === 'true' || false

// Generate a unique request ID for each request
function generateRequestId(): string {
  return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`
}

// Get client version from build metadata or package.json
function getClientVersion(): string {
  // In production, this could come from build-time env var
  // For now, use a simple version string
  return import.meta.env.VITE_APP_VERSION || '1.0.0'
}

// Get current workspace ID from context (will be injected by services)
export interface GatewayRequestOptions {
  workspaceId?: string | number
  moduleKey?: string // Module scope for gateway routing (e.g., 'qynsight', 'qynrun')
  headers?: Record<string, string>
}

/**
 * Builds a gateway URL for a given endpoint
 * If gateway is enabled, routes through /gateway/api/{workspaceId}/...
 * Otherwise, routes directly to /api/...
 */
function buildGatewayUrl(endpoint: string, options?: GatewayRequestOptions): string {
  // Remove leading slash if present
  const cleanEndpoint = endpoint.startsWith('/') ? endpoint.slice(1) : endpoint

  if (USE_GATEWAY && options?.workspaceId) {
    // Route through gateway: /gateway/api/{workspaceId}/...
    return `/gateway/api/${options.workspaceId}/${cleanEndpoint}`
  }

  // Direct API route (current behavior)
  return `/api/${cleanEndpoint}`
}

/**
 * Builds headers with module scope information for gateway middleware
 */
function buildGatewayHeaders(options?: GatewayRequestOptions): Record<string, string> {
  const headers: Record<string, string> = {
    ...options?.headers,
  }

  // Add workspace ID header for gateway scoping
  if (options?.workspaceId) {
    headers['x-workspace-id'] = String(options.workspaceId)
  }

  // Add module key header for gateway scoping (defaults to 'qynsight' for observeService)
  if (options?.moduleKey) {
    headers['x-module-key'] = options.moduleKey
  }

  // Add standard gateway headers
  headers['x-request-id'] = generateRequestId()
  headers['x-client-version'] = getClientVersion()

  return headers
}

/**
 * Gateway client wrapper around apiClient
 * All future API calls should use this instead of apiClient directly
 */
export const gatewayClient = {
  /**
   * GET request through gateway
   */
  async get<T>(endpoint: string, options?: GatewayRequestOptions): Promise<T> {
    const url = buildGatewayUrl(endpoint, options)
    const headers = buildGatewayHeaders(options)
    return apiClient.get<T>(url, headers)
  },

  /**
   * POST request through gateway
   */
  async post<T>(endpoint: string, body?: unknown, options?: GatewayRequestOptions): Promise<T> {
    const url = buildGatewayUrl(endpoint, options)
    const headers = buildGatewayHeaders(options)
    return apiClient.post<T>(url, body, headers)
  },

  /**
   * PUT request through gateway
   */
  async put<T>(endpoint: string, body?: unknown, options?: GatewayRequestOptions): Promise<T> {
    const url = buildGatewayUrl(endpoint, options)
    const headers = buildGatewayHeaders(options)
    return apiClient.put<T>(url, body, headers)
  },

  /**
   * DELETE request through gateway
   */
  async delete<T>(endpoint: string, options?: GatewayRequestOptions): Promise<T> {
    const url = buildGatewayUrl(endpoint, options)
    const headers = buildGatewayHeaders(options)
    return apiClient.delete<T>(url, headers)
  },
}

// Export for use in services
export default gatewayClient

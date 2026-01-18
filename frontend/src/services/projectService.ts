import { apiClient, ApiResponse } from './apiClient'
import { CreateProjectInput, Project, UpdateProjectInput } from '../types/project'

/**
 * Normalizes response data that may be wrapped in a nested `data` property.
 * Handles both shapes:
 * - Direct: Project[] or Project
 * - Wrapped: { data: Project[] } or { data: Project }
 */
function normalizeResponseData<T>(payload: unknown): T {
  // If it's already the expected type (array or object), return it
  if (Array.isArray(payload)) {
    return payload as T
  }
  
  // If it's an object with a `data` property that's an array, unwrap it
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const wrapped = payload as { data: unknown }
    if (Array.isArray(wrapped.data)) {
      return wrapped.data as T
    }
    // For single objects wrapped in { data: {...} }
    return wrapped.data as T
  }
  
  // Fallback: return as-is (shouldn't happen but handle gracefully)
  return payload as T
}

export const projectService = {
  async listProjects(): Promise<ApiResponse<Project[]>> {
    const response = await apiClient.get<Project[] | { data: Project[] }>('/api/projects')
    if (!response.success) {
      return response
    }
    return {
      success: true,
      data: normalizeResponseData<Project[]>(response.data),
    }
  },
  async getProject(id: number): Promise<ApiResponse<Project>> {
    const response = await apiClient.get<Project | { data: Project }>(`/api/projects/${id}`)
    if (!response.success) {
      return response
    }
    return {
      success: true,
      data: normalizeResponseData<Project>(response.data),
    }
  },
  async createProject(payload: CreateProjectInput): Promise<ApiResponse<Project>> {
    const response = await apiClient.post<Project | { data: Project }>('/api/projects', payload)
    if (!response.success) {
      return response
    }
    return {
      success: true,
      data: normalizeResponseData<Project>(response.data),
    }
  },
  async updateProject(id: number, payload: UpdateProjectInput): Promise<ApiResponse<Project>> {
    const response = await apiClient.put<Project | { data: Project }>(`/api/projects/${id}`, payload)
    if (!response.success) {
      return response
    }
    return {
      success: true,
      data: normalizeResponseData<Project>(response.data),
    }
  },
  deleteProject(id: number): Promise<ApiResponse<null>> {
    return apiClient.delete<null>(`/api/projects/${id}`)
  },
}

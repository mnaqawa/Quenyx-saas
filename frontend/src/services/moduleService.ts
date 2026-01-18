import { apiClient, ApiResponse } from './apiClient'
import type { Module } from './dashboardService'
import { ModuleCatalog, ModuleWithAccess, ProjectModuleAccess } from '../types/module'

interface ModulesResponse {
  data: Module[]
}

export const moduleService = {
  async getModules(): Promise<Module[]> {
    const response = await apiClient.get<ModulesResponse>('/api/modules')
    if (!response.success) {
      throw new Error(response.message)
    }
    return response.data.data
  },

  /**
   * Get all modules catalog (key, name, description, status)
   */
  async getModulesCatalog(): Promise<ApiResponse<ModuleCatalog[]>> {
    const response = await apiClient.get<ModuleCatalog[] | { data: ModuleCatalog[] }>('/api/modules')
    if (!response.success) {
      return response
    }
    // Normalize response (handle both shapes)
    const data = Array.isArray(response.data) 
      ? response.data 
      : (response.data as { data: ModuleCatalog[] }).data
    return {
      success: true,
      data,
    }
  },

  /**
   * Get project modules with access flags (merged catalog + access)
   */
  async getProjectModules(projectId: number): Promise<ApiResponse<ModuleWithAccess[]>> {
    const response = await apiClient.get<ModuleWithAccess[] | { data: ModuleWithAccess[] }>(
      `/api/projects/${projectId}/modules`
    )
    if (!response.success) {
      return response
    }
    // Normalize response
    const data = Array.isArray(response.data)
      ? response.data
      : (response.data as { data: ModuleWithAccess[] }).data
    return {
      success: true,
      data,
    }
  },

  /**
   * Get project module access overlay
   */
  async getProjectModuleAccess(projectId: number): Promise<ApiResponse<ProjectModuleAccess>> {
    const response = await apiClient.get<ProjectModuleAccess | { data: ProjectModuleAccess }>(
      `/api/projects/${projectId}/modules/access`
    )
    if (!response.success) {
      return response
    }
    // Normalize response
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as ProjectModuleAccess
    return {
      success: true,
      data,
    }
  },

  /**
   * Update module override for a project
   */
  async updateModuleOverride(
    projectId: number,
    moduleKey: string,
    mode: 'allow' | 'deny' | null
  ): Promise<ApiResponse<ModuleWithAccess>> {
    const response = await apiClient.put<ModuleWithAccess | { data: ModuleWithAccess }>(
      `/api/projects/${projectId}/modules/${moduleKey}/override`,
      { mode }
    )
    if (!response.success) {
      return response
    }
    // Normalize response
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as ModuleWithAccess
    return {
      success: true,
      data,
    }
  },
}

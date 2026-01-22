import { apiClient } from './apiClient'
import type { Module } from './dashboardService'
import { ModuleCatalog, ModuleWithAccess, ProjectModuleAccess } from '../types/module'

interface ModulesResponse {
  data: Module[]
}

export const moduleService = {
  async getModules(): Promise<Module[]> {
    const response = await apiClient.get<ModulesResponse>('/api/modules')
    return response.data
  },

  /**
   * Get all modules catalog (key, name, description, status)
   */
  async getModulesCatalog(): Promise<ModuleCatalog[]> {
    // apiClient unwraps { success: true, data: ... } so response is already ModuleCatalog[]
    return apiClient.get<ModuleCatalog[]>('/api/modules')
  },

  /**
   * Get project modules with access flags (merged catalog + access)
   */
  async getProjectModules(projectId: number): Promise<ModuleWithAccess[]> {
    // apiClient unwraps { success: true, data: ... } so response is already ModuleWithAccess[]
    return apiClient.get<ModuleWithAccess[]>(`/api/workspaces/${projectId}/modules`)
  },

  /**
   * Get project module access overlay
   */
  async getProjectModuleAccess(projectId: number): Promise<ProjectModuleAccess> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectModuleAccess
    return apiClient.get<ProjectModuleAccess>(`/api/workspaces/${projectId}/modules/access`)
  },

  /**
   * Update module override for a project
   */
  async updateModuleOverride(
    projectId: number,
    moduleKey: string,
    mode: 'allow' | 'deny' | null
  ): Promise<ModuleWithAccess> {
    // apiClient unwraps { success: true, data: ... } so response is already ModuleWithAccess
    return apiClient.put<ModuleWithAccess>(
      `/api/workspaces/${projectId}/modules/${moduleKey}/override`,
      { mode }
    )
  },

  /**
   * Get audit logs for a project
   */
  async getProjectAuditLogs(projectId: number): Promise<AuditLog[]> {
    // apiClient unwraps { success: true, data: ... } so response is already AuditLog[]
    return apiClient.get<AuditLog[]>(`/api/workspaces/${projectId}/audit-logs`)
  },
}

export interface AuditLog {
  id: number
  action: string
  metadata: {
    module_key?: string
    module_name?: string
    old_mode?: string | null
    new_mode?: string | null
    allowed_by_plan?: boolean
    [key: string]: any
  }
  timestamp: string
  created_at: string
  user: {
    id: number
    name: string
    email: string
  } | null
}

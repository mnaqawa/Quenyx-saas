import { apiClient } from './apiClient'
import { WorkspaceListItem } from '../types/workspace'
import { CreateProjectInput, Project, UpdateProjectInput } from '../types/project'

export const workspaceService = {
  async getMyWorkspaces(): Promise<WorkspaceListItem[]> {
    // Backend returns { success: true, data: Array<{ project, my_role }> }
    // apiClient unwraps it, so response is already WorkspaceListItem[]
    return apiClient.get<WorkspaceListItem[]>('/api/projects')
  },

  // Consolidated from projectService.ts - these still call /api/projects endpoints
  async listWorkspaces(): Promise<Project[]> {
    // apiClient unwraps { success: true, data: ... } so response is already Project[]
    return apiClient.get<Project[]>('/api/projects')
  },

  async getWorkspace(id: number): Promise<Project> {
    // apiClient unwraps { success: true, data: ... } so response is already Project
    return apiClient.get<Project>(`/api/projects/${id}`)
  },

  async createWorkspace(payload: CreateProjectInput): Promise<Project> {
    // apiClient unwraps { success: true, data: ... } so response is already Project
    return apiClient.post<Project>('/api/projects', payload)
  },

  async updateWorkspace(id: number, payload: UpdateProjectInput): Promise<Project> {
    // apiClient unwraps { success: true, data: ... } so response is already Project
    return apiClient.put<Project>(`/api/projects/${id}`, payload)
  },

  async deleteWorkspace(id: number): Promise<void> {
    // apiClient unwraps { success: true, data: ... } so response is already void/undefined
    await apiClient.delete(`/api/projects/${id}`)
  },
}

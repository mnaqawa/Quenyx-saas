import { apiClient } from './apiClient'
import { CreateProjectInput, Project, UpdateProjectInput } from '../types/project'

export const projectService = {
  async listProjects(): Promise<Project[]> {
    // apiClient unwraps { success: true, data: ... } so response is already Project[]
    return apiClient.get<Project[]>('/api/projects')
  },
  async getProject(id: number): Promise<Project> {
    // apiClient unwraps { success: true, data: ... } so response is already Project
    return apiClient.get<Project>(`/api/projects/${id}`)
  },
  async createProject(payload: CreateProjectInput): Promise<Project> {
    // apiClient unwraps { success: true, data: ... } so response is already Project
    return apiClient.post<Project>('/api/projects', payload)
  },
  async updateProject(id: number, payload: UpdateProjectInput): Promise<Project> {
    // apiClient unwraps { success: true, data: ... } so response is already Project
    return apiClient.put<Project>(`/api/projects/${id}`, payload)
  },
  async deleteProject(id: number): Promise<void> {
    // apiClient unwraps { success: true, data: ... } so response is already void/undefined
    await apiClient.delete(`/api/projects/${id}`)
  },
}

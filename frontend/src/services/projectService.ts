import { apiClient, ApiResponse } from './apiClient'
import { CreateProjectInput, Project, UpdateProjectInput } from '../types/project'

export const projectService = {
  listProjects(): Promise<ApiResponse<Project[]>> {
    return apiClient.get<Project[]>('/api/projects')
  },
  getProject(id: number): Promise<ApiResponse<Project>> {
    return apiClient.get<Project>(`/api/projects/${id}`)
  },
  createProject(payload: CreateProjectInput): Promise<ApiResponse<Project>> {
    return apiClient.post<Project>('/api/projects', payload)
  },
  updateProject(id: number, payload: UpdateProjectInput): Promise<ApiResponse<Project>> {
    return apiClient.put<Project>(`/api/projects/${id}`, payload)
  },
  deleteProject(id: number): Promise<ApiResponse<null>> {
    return apiClient.delete<null>(`/api/projects/${id}`)
  },
}

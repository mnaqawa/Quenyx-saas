import { apiClient, ApiResponse } from './apiClient'

export interface ProjectMember {
  id: number | null
  user_id: number
  user: {
    id: number
    name: string
    email: string
  }
  role: 'owner' | 'admin' | 'member' | 'viewer'
  created_at: string
}

export const projectMemberService = {
  async getProjectMembers(projectId: number): Promise<ApiResponse<ProjectMember[]>> {
    const response = await apiClient.get<ProjectMember[] | { data:ProjectMember[] }>(
      `/api/projects/${projectId}/members`
    )
    if (!response.success) {
      return response
    }
    const data = Array.isArray(response.data)
      ? response.data
      : (response.data as { data: ProjectMember[] }).data
    return {
      success: true,
      data,
    }
  },

  async addMember(projectId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<ApiResponse<ProjectMember>> {
    const response = await apiClient.post<ProjectMember | { data: ProjectMember }>(
      `/api/projects/${projectId}/members`,
      { email, role }
    )
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as ProjectMember
    return {
      success: true,
      data,
    }
  },

  async updateMemberRole(projectId: number, userId: number, role: 'admin' | 'member' | 'viewer'): Promise<ApiResponse<ProjectMember>> {
    const response = await apiClient.put<ProjectMember | { data: ProjectMember }>(
      `/api/projects/${projectId}/members/${userId}`,
      { role }
    )
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data && response.data.data
      ? response.data.data
      : response.data as ProjectMember
    return {
      success: true,
      data,
    }
  },

  async removeMember(projectId: number, userId: number): Promise<ApiResponse<void>> {
    const response = await apiClient.delete(`/api/projects/${projectId}/members/${userId}`)
    if (!response.success) {
      return response as ApiResponse<void>
    }
    return {
      success: true,
      data: undefined,
    }
  },
}

import { apiClient } from './apiClient'

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
  async getProjectMembers(projectId: number): Promise<ProjectMember[]> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectMember[]
    return apiClient.get<ProjectMember[]>(`/api/projects/${projectId}/members`)
  },

  async addMember(projectId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<ProjectMember> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectMember
    return apiClient.post<ProjectMember>(`/api/projects/${projectId}/members`, { email, role })
  },

  async updateMemberRole(projectId: number, userId: number, role: 'admin' | 'member' | 'viewer'): Promise<ProjectMember> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectMember
    return apiClient.put<ProjectMember>(`/api/projects/${projectId}/members/${userId}`, { role })
  },

  async removeMember(projectId: number, userId: number): Promise<void> {
    // apiClient unwraps { success: true, data: ... } so response is already void/undefined
    await apiClient.delete(`/api/projects/${projectId}/members/${userId}`)
  },
}

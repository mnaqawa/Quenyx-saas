import { apiClient } from './apiClient'

export interface ProjectMembership {
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

export interface ProjectInvite {
  id: number
  email: string
  role: 'admin' | 'member' | 'viewer'
  status: 'pending' | 'accepted' | 'rejected' | 'expired'
  invited_by: {
    id: number
    name: string
  }
  created_at: string
  expires_at: string | null
}

export interface ProjectMembershipsResponse {
  memberships: ProjectMembership[]
  invites: ProjectInvite[]
}

export const projectMembershipService = {
  async getProjectMemberships(projectId: number): Promise<ProjectMembershipsResponse> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectMembershipsResponse
    return apiClient.get<ProjectMembershipsResponse>(`/api/projects/${projectId}/memberships`)
  },

  async addMember(projectId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<ProjectMembership> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectMembership
    return apiClient.post<ProjectMembership>(`/api/projects/${projectId}/memberships`, { email, role })
  },

  async createInvite(projectId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<ProjectInvite> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectInvite
    return apiClient.post<ProjectInvite>(`/api/projects/${projectId}/memberships/invite`, { email, role })
  },

  async updateMembershipRole(projectId: number, membershipId: number, role: 'owner' | 'admin' | 'member' | 'viewer'): Promise<ProjectMembership> {
    // apiClient unwraps { success: true, data: ... } so response is already ProjectMembership
    return apiClient.put<ProjectMembership>(`/api/projects/${projectId}/memberships/${membershipId}`, { role })
  },

  async removeMembership(projectId: number, membershipId: number): Promise<void> {
    // apiClient unwraps { success: true, data: ... } so response is already void/undefined
    await apiClient.delete(`/api/projects/${projectId}/memberships/${membershipId}`)
  },
}

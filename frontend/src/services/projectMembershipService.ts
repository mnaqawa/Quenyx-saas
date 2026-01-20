import { apiClient, ApiResponse } from './apiClient'

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
  async getProjectMemberships(projectId: number): Promise<ApiResponse<ProjectMembershipsResponse>> {
    const response = await apiClient.get<{ success: boolean; data: ProjectMembershipsResponse }>(
      `/api/projects/${projectId}/memberships`
    )
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data,
    }
  },

  async addMember(projectId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<ApiResponse<ProjectMembership>> {
    const response = await apiClient.post<{ success: boolean; data: ProjectMembership }>(
      `/api/projects/${projectId}/memberships`,
      { email, role }
    )
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data,
    }
  },

  async createInvite(projectId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<ApiResponse<ProjectInvite>> {
    const response = await apiClient.post<{ success: boolean; data: ProjectInvite }>(
      `/api/projects/${projectId}/memberships/invite`,
      { email, role }
    )
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data,
    }
  },

  async updateMembershipRole(projectId: number, membershipId: number, role: 'owner' | 'admin' | 'member' | 'viewer'): Promise<ApiResponse<ProjectMembership>> {
    const response = await apiClient.put<{ success: boolean; data: ProjectMembership }>(
      `/api/projects/${projectId}/memberships/${membershipId}`,
      { role }
    )
    if (!response.success) {
      return response
    }
    const data = 'data' in response.data ? response.data.data : (response.data as any)
    return {
      success: true,
      data,
    }
  },

  async removeMembership(projectId: number, membershipId: number): Promise<ApiResponse<void>> {
    const response = await apiClient.delete(`/api/projects/${projectId}/memberships/${membershipId}`)
    if (!response.success) {
      return response as ApiResponse<void>
    }
    return {
      success: true,
      data: undefined,
    }
  },
}

import { apiClient } from './apiClient'

export interface WorkspaceMembership {
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

export interface WorkspaceInvite {
  id: number
  email: string
  role: 'admin' | 'member' | 'viewer'
  status: 'pending' | 'accepted' | 'rejected' | 'expired'
  token?: string
  invited_by: {
    id: number
    name: string
  }
  created_at: string
  expires_at: string | null
}

export interface WorkspaceMembershipsResponse {
  memberships: WorkspaceMembership[]
  invites: WorkspaceInvite[]
}

export const workspaceMembershipService = {
  async getWorkspaceMemberships(workspaceId: number): Promise<WorkspaceMembershipsResponse> {
    // apiClient unwraps { success: true, data: ... } so response is already WorkspaceMembershipsResponse
    return apiClient.get<WorkspaceMembershipsResponse>(`/api/workspaces/${workspaceId}/memberships`)
  },

  async addMember(workspaceId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<WorkspaceMembership> {
    // apiClient unwraps { success: true, data: ... } so response is already WorkspaceMembership
    return apiClient.post<WorkspaceMembership>(`/api/workspaces/${workspaceId}/memberships`, { email, role })
  },

  async createInvite(workspaceId: number, email: string, role: 'admin' | 'member' | 'viewer'): Promise<WorkspaceInvite> {
    // apiClient unwraps { success: true, data: ... } so response is already WorkspaceInvite
    return apiClient.post<WorkspaceInvite>(`/api/workspaces/${workspaceId}/memberships/invite`, { email, role })
  },

  async updateMembershipRole(workspaceId: number, membershipId: number, role: 'owner' | 'admin' | 'member' | 'viewer'): Promise<WorkspaceMembership> {
    // apiClient unwraps { success: true, data: ... } so response is already WorkspaceMembership
    return apiClient.put<WorkspaceMembership>(`/api/workspaces/${workspaceId}/memberships/${membershipId}`, { role })
  },

  async removeMembership(workspaceId: number, membershipId: number): Promise<void> {
    // apiClient unwraps { success: true, data: ... } so response is already void/undefined
    await apiClient.delete(`/api/workspaces/${workspaceId}/memberships/${membershipId}`)
  },
}

import { apiClient } from './apiClient'
import { InviteAcceptanceResponse } from '../types/workspace'

export const inviteService = {
  async acceptInvite(token: string): Promise<InviteAcceptanceResponse> {
    // Backend returns { success: true, data: { membership, project } }
    // apiClient unwraps it, so response is already InviteAcceptanceResponse
    return apiClient.post<InviteAcceptanceResponse>(`/api/invites/${token}/accept`)
  },
}

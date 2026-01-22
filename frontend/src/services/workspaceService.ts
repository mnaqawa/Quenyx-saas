import { apiClient } from './apiClient'
import { WorkspaceListItem } from '../types/workspace'

export const workspaceService = {
  async getMyWorkspaces(): Promise<WorkspaceListItem[]> {
    // Backend returns { success: true, data: Array<{ project, my_role }> }
    // apiClient unwraps it, so response is already WorkspaceListItem[]
    return apiClient.get<WorkspaceListItem[]>('/api/projects')
  },
}

// Shared types for workspace/company flow

export type Role = 'owner' | 'admin' | 'member' | 'viewer'

export interface ProjectSummary {
  id: number
  name: string
  status: string
  created_at: string
  updated_at: string
}

export interface WorkspaceListItem {
  project: ProjectSummary
  my_role: Role
}

export interface InviteAcceptanceResponse {
  membership: {
    id: number
    user_id: number
    user: {
      id: number
      name: string
      email: string
    }
    role: Role
    created_at: string
  }
  project: {
    id: number
    name: string
    status: string
  }
}

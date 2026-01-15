export type ProjectStatus = 'active' | 'paused' | 'archived'

export interface Project {
  id: number
  name: string
  status: ProjectStatus
  created_at: string
  updated_at: string
}

export interface CreateProjectInput {
  name: string
  status?: ProjectStatus
}

export interface UpdateProjectInput {
  name?: string
  status?: ProjectStatus
}

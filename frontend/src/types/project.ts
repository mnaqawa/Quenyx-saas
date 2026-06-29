export type ProjectStatus = 'active' | 'paused' | 'archived'

export interface Project {
  id: number
  // Public, non-enumerable identifier used by platform-level (non-nested) APIs such as the
  // Unified AI Workspace. Backward-compatible additive field (numeric `id` is still primary).
  uuid?: string
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

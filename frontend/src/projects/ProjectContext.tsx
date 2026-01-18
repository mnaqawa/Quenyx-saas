import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import { Project } from '../types/project'
import { projectService } from '../services/projectService'
import { getAuthToken } from '../services/apiClient'

interface ProjectContextValue {
  projects: Project[]
  selectedProject: Project | null
  selectedProjectId: number | null
  setSelectedProjectId: (projectId: number) => void
  refreshProjects: () => Promise<void>
}

const ProjectContext = createContext<ProjectContextValue | undefined>(undefined)

const STORAGE_KEY = 'portshield.selected_project_id'

export function ProjectProvider({ children }: { children: React.ReactNode }) {
  const [projects, setProjects] = useState<Project[]>([])
  const [selectedProjectId, setSelectedProjectIdState] = useState<number | null>(null)

  const refreshProjects = async () => {
    if (!getAuthToken()) {
      setProjects([])
      setSelectedProjectIdState(null)
      return
    }
    const response = await projectService.listProjects()
    if (response.success) {
      setProjects(response.data)
      const stored = localStorage.getItem(STORAGE_KEY)
      const storedId = stored ? Number(stored) : null
      const validId =
        storedId && response.data.some((project) => project.id === storedId)
          ? storedId
          : response.data[0]?.id ?? null
      setSelectedProjectIdState(validId)
      if (validId) {
        localStorage.setItem(STORAGE_KEY, String(validId))
      }
    }
  }

  useEffect(() => {
    refreshProjects()
  }, [])

  const setSelectedProjectId = (projectId: number) => {
    setSelectedProjectIdState(projectId)
    localStorage.setItem(STORAGE_KEY, String(projectId))
  }

  const value = useMemo<ProjectContextValue>(() => {
    const selectedProject =
      projects.find((project) => project.id === selectedProjectId) ?? null
    return {
      projects,
      selectedProject,
      selectedProjectId,
      setSelectedProjectId,
      refreshProjects,
    }
  }, [projects, selectedProjectId])

  return <ProjectContext.Provider value={value}>{children}</ProjectContext.Provider>
}

export function useProjectContext() {
  const context = useContext(ProjectContext)
  if (!context) {
    throw new Error('useProjectContext must be used within ProjectProvider')
  }
  return context
}

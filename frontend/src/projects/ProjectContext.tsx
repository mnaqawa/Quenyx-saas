import { createContext, useContext, useEffect, useMemo, useState, useCallback } from 'react'
import { Project } from '../types/project'
import { ProjectEntitlements } from '../types/subscription'
import { projectService } from '../services/projectService'
import { subscriptionService } from '../services/subscriptionService'
import { getAuthToken } from '../services/apiClient'

interface ProjectContextValue {
  projects: Project[]
  selectedProject: Project | null
  selectedProjectId: number | null
  setSelectedProjectId: (projectId: number) => void
  refreshProjects: () => Promise<void>
  entitlements: ProjectEntitlements | null
  isLoadingEntitlements: boolean
  refreshEntitlements: () => Promise<void>
}

const ProjectContext = createContext<ProjectContextValue | undefined>(undefined)

const STORAGE_KEY = 'portshield.selected_project_id'

export function ProjectProvider({ children }: { children: React.ReactNode }) {
  const [projects, setProjects] = useState<Project[]>([])
  const [selectedProjectId, setSelectedProjectIdState] = useState<number | null>(null)
  const [entitlements, setEntitlements] = useState<ProjectEntitlements | null>(null)
  const [isLoadingEntitlements, setIsLoadingEntitlements] = useState(false)

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

  const refreshEntitlements = useCallback(async () => {
    if (!getAuthToken() || !selectedProjectId) {
      setEntitlements(null)
      return
    }

    setIsLoadingEntitlements(true)
    try {
      const response = await subscriptionService.getProjectEntitlements(selectedProjectId)
      if (response.success) {
        setEntitlements(response.data)
      } else {
        setEntitlements(null)
      }
    } catch (err) {
      setEntitlements(null)
    } finally {
      setIsLoadingEntitlements(false)
    }
  }, [selectedProjectId])

  useEffect(() => {
    refreshProjects()
  }, [])

  useEffect(() => {
    refreshEntitlements()
  }, [refreshEntitlements])

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
      entitlements,
      isLoadingEntitlements,
      refreshEntitlements,
    }
  }, [projects, selectedProjectId, entitlements, isLoadingEntitlements, refreshEntitlements])

  return <ProjectContext.Provider value={value}>{children}</ProjectContext.Provider>
}

export function useProjectContext() {
  const context = useContext(ProjectContext)
  if (!context) {
    throw new Error('useProjectContext must be used within ProjectProvider')
  }
  return context
}

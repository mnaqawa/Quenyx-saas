import { createContext, useContext, useEffect, useMemo, useState, useCallback } from 'react'
import { Project } from '../types/project'
import { ProjectEntitlements } from '../types/subscription'
import { ModuleWithAccess } from '../types/module'
import { projectService } from '../services/projectService'
import { subscriptionService } from '../services/subscriptionService'
import { moduleService } from '../services/moduleService'
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
  modulesWithAccess: ModuleWithAccess[] | null
  isLoadingModules: boolean
  modulesError: string | null
  refreshModules: () => Promise<void>
  allowedByKey: Record<string, boolean>
}

const ProjectContext = createContext<ProjectContextValue | undefined>(undefined)

const STORAGE_KEY = 'portshield.selected_project_id'

export function ProjectProvider({ children }: { children: React.ReactNode }) {
  const [projects, setProjects] = useState<Project[]>([])
  const [selectedProjectId, setSelectedProjectIdState] = useState<number | null>(null)
  const [entitlements, setEntitlements] = useState<ProjectEntitlements | null>(null)
  const [isLoadingEntitlements, setIsLoadingEntitlements] = useState(false)
  const [modulesWithAccess, setModulesWithAccess] = useState<ModuleWithAccess[] | null>(null)
  const [isLoadingModules, setIsLoadingModules] = useState(false)
  const [modulesError, setModulesError] = useState<string | null>(null)

  const refreshProjects = async () => {
    if (!getAuthToken()) {
      setProjects([])
      setSelectedProjectIdState(null)
      return
    }
    try {
      const projects = await projectService.listProjects()
      setProjects(projects)
      const stored = localStorage.getItem(STORAGE_KEY)
      const storedId = stored ? Number(stored) : null
      const validId =
        storedId && projects.some((project) => project.id === storedId)
          ? storedId
          : projects[0]?.id ?? null
      setSelectedProjectIdState(validId)
      if (validId) {
        localStorage.setItem(STORAGE_KEY, String(validId))
      }
    } catch (err) {
      // Ignore errors
      setProjects([])
    }
  }

  const refreshEntitlements = useCallback(async () => {
    if (!getAuthToken() || !selectedProjectId) {
      setEntitlements(null)
      return
    }

    setIsLoadingEntitlements(true)
    try {
      const entitlements = await subscriptionService.getProjectEntitlements(selectedProjectId)
      setEntitlements(entitlements)
    } catch (err) {
      setEntitlements(null)
    } finally {
      setIsLoadingEntitlements(false)
    }
  }, [selectedProjectId])

  useEffect(() => {
    refreshProjects()
  }, [])

  const refreshModules = useCallback(async () => {
    if (!getAuthToken() || !selectedProjectId) {
      setModulesWithAccess(null)
      setModulesError(null)
      return
    }

    setIsLoadingModules(true)
    setModulesError(null)
    try {
      const modules = await moduleService.getProjectModules(selectedProjectId)
      // Deduplicate modules by key (defensive filter)
      // Use Map to ensure true uniqueness - only first occurrence of each key is kept
      const moduleMap = new Map<string, typeof modules[0]>()
      modules.forEach((module) => {
          if (module?.key && !moduleMap.has(module.key)) {
            moduleMap.set(module.key, module)
          } else if (module?.key && moduleMap.has(module.key)) {
            // Log duplicate detection for debugging
            console.warn(`Duplicate module key detected: ${module.key}`, module)
          }
        })
        const uniqueModules = Array.from(moduleMap.values())
        
        // Final verification: ensure no duplicates in final array
        const finalKeys = new Set<string>()
        const verifiedModules = uniqueModules.filter((module) => {
          if (!module.key) return false
          if (finalKeys.has(module.key)) {
            console.error(`Duplicate module key in final array: ${module.key}`)
            return false
          }
          finalKeys.add(module.key)
          return true
        })
        
      setModulesWithAccess(verifiedModules)
      setModulesError(null)
    } catch (err) {
      setModulesWithAccess(null)
      const errorMessage = err instanceof Error ? err.message : 'Failed to load modules'
      setModulesError(errorMessage)
      console.error('Failed to load modules:', err)
    } finally {
      setIsLoadingModules(false)
    }
  }, [selectedProjectId])

  useEffect(() => {
    refreshEntitlements()
    refreshModules()
  }, [refreshEntitlements, refreshModules])

  const setSelectedProjectId = (projectId: number) => {
    setSelectedProjectIdState(projectId)
    localStorage.setItem(STORAGE_KEY, String(projectId))
  }

  const allowedByKey = useMemo(() => {
    const lookup: Record<string, boolean> = {}
    if (modulesWithAccess) {
      modulesWithAccess.forEach((module) => {
        lookup[module.key] = module.allowed
      })
    }
    return lookup
  }, [modulesWithAccess])

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
      modulesWithAccess,
      isLoadingModules,
      modulesError,
      refreshModules,
      allowedByKey,
    }
  }, [
    projects,
    selectedProjectId,
    entitlements,
    isLoadingEntitlements,
    refreshEntitlements,
    modulesWithAccess,
    isLoadingModules,
    modulesError,
    refreshModules,
    allowedByKey,
  ])

  return <ProjectContext.Provider value={value}>{children}</ProjectContext.Provider>
}

export function useProjectContext() {
  const context = useContext(ProjectContext)
  if (!context) {
    throw new Error('useProjectContext must be used within ProjectProvider')
  }
  return context
}

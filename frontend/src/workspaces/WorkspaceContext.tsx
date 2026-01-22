import { createContext, useContext, useEffect, useMemo, useState, useCallback } from 'react'
import { Project } from '../types/project'
import { ProjectEntitlements } from '../types/subscription'
import { ModuleWithAccess } from '../types/module'
import { workspaceService } from '../services/workspaceService'
import { subscriptionService } from '../services/subscriptionService'
import { moduleService } from '../services/moduleService'
import { getAuthToken } from '../services/apiClient'

interface WorkspaceContextValue {
  workspaces: Project[] // Keep Project type since backend returns Project
  selectedWorkspace: Project | null
  selectedWorkspaceId: number | null
  setSelectedWorkspaceId: (workspaceId: number) => void
  refreshWorkspaces: () => Promise<void>
  entitlements: ProjectEntitlements | null
  isLoadingEntitlements: boolean
  refreshEntitlements: () => Promise<void>
  modulesWithAccess: ModuleWithAccess[] | null
  isLoadingModules: boolean
  modulesError: string | null
  refreshModules: () => Promise<void>
  allowedByKey: Record<string, boolean>
}

const WorkspaceContext = createContext<WorkspaceContextValue | undefined>(undefined)

const STORAGE_KEY = 'portshield.selected_workspace_id'
const OLD_STORAGE_KEY = 'portshield.selected_project_id' // For backward compatibility

export function WorkspaceProvider({ children }: { children: React.ReactNode }) {
  const [workspaces, setWorkspaces] = useState<Project[]>([])
  const [selectedWorkspaceId, setSelectedWorkspaceIdState] = useState<number | null>(null)
  const [entitlements, setEntitlements] = useState<ProjectEntitlements | null>(null)
  const [isLoadingEntitlements, setIsLoadingEntitlements] = useState(false)
  const [modulesWithAccess, setModulesWithAccess] = useState<ModuleWithAccess[] | null>(null)
  const [isLoadingModules, setIsLoadingModules] = useState(false)
  const [modulesError, setModulesError] = useState<string | null>(null)

  const refreshWorkspaces = async () => {
    if (!getAuthToken()) {
      setWorkspaces([])
      setSelectedWorkspaceIdState(null)
      return
    }
    try {
      const workspaceListItems = await workspaceService.getMyWorkspaces()
      // Extract Project objects from WorkspaceListItem[]
      // Convert ProjectSummary to Project (they have compatible structure)
      const projects: Project[] = workspaceListItems.map((item) => ({
        ...item.project,
        status: item.project.status as Project['status'],
      }))
      setWorkspaces(projects)
      // Backward compatibility: try new key first, then old key
      let stored = localStorage.getItem(STORAGE_KEY)
      if (!stored) {
        stored = localStorage.getItem(OLD_STORAGE_KEY)
        if (stored) {
          // Migrate old key to new key
          localStorage.setItem(STORAGE_KEY, stored)
          localStorage.removeItem(OLD_STORAGE_KEY)
        }
      }
      const storedId = stored ? Number(stored) : null
      const validId =
        storedId && projects.some((workspace) => workspace.id === storedId)
          ? storedId
          : projects[0]?.id ?? null
      setSelectedWorkspaceIdState(validId)
      if (validId) {
        localStorage.setItem(STORAGE_KEY, String(validId))
      }
    } catch (err) {
      // Ignore errors
      setWorkspaces([])
    }
  }

  const refreshEntitlements = useCallback(async () => {
    if (!getAuthToken() || !selectedWorkspaceId) {
      setEntitlements(null)
      return
    }

    setIsLoadingEntitlements(true)
    try {
      const entitlements = await subscriptionService.getProjectEntitlements(selectedWorkspaceId)
      setEntitlements(entitlements)
    } catch (err) {
      setEntitlements(null)
    } finally {
      setIsLoadingEntitlements(false)
    }
  }, [selectedWorkspaceId])

  useEffect(() => {
    refreshWorkspaces()
  }, [])

  const refreshModules = useCallback(async () => {
    if (!getAuthToken() || !selectedWorkspaceId) {
      setModulesWithAccess(null)
      setModulesError(null)
      return
    }

    setIsLoadingModules(true)
    setModulesError(null)
    try {
      const modules = await moduleService.getProjectModules(selectedWorkspaceId)
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
  }, [selectedWorkspaceId])

  useEffect(() => {
    refreshEntitlements()
    refreshModules()
  }, [refreshEntitlements, refreshModules])

  const setSelectedWorkspaceId = (workspaceId: number) => {
    setSelectedWorkspaceIdState(workspaceId)
    localStorage.setItem(STORAGE_KEY, String(workspaceId))
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

  const value = useMemo<WorkspaceContextValue>(() => {
    const selectedWorkspace =
      workspaces.find((workspace) => workspace.id === selectedWorkspaceId) ?? null
    return {
      workspaces,
      selectedWorkspace,
      selectedWorkspaceId,
      setSelectedWorkspaceId,
      refreshWorkspaces,
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
    workspaces,
    selectedWorkspaceId,
    entitlements,
    isLoadingEntitlements,
    refreshEntitlements,
    modulesWithAccess,
    isLoadingModules,
    modulesError,
    refreshModules,
    allowedByKey,
  ])

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>
}

export function useWorkspaceContext() {
  const context = useContext(WorkspaceContext)
  if (!context) {
    throw new Error('useWorkspaceContext must be used within WorkspaceProvider')
  }
  return context
}

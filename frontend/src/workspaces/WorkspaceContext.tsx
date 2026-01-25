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
  selectedWorkspaceId: string | null
  setSelectedWorkspaceId: (workspaceId: string | number) => void
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
  // Initialize selectedWorkspaceId from localStorage on mount for immediate display
  // Keep as string to match localStorage storage format
  const [selectedWorkspaceId, setSelectedWorkspaceIdState] = useState<string | null>(() => {
    if (typeof window === 'undefined') return null
    const stored = localStorage.getItem(STORAGE_KEY) || localStorage.getItem(OLD_STORAGE_KEY)
    return stored || null
  })
  const [entitlements, setEntitlements] = useState<ProjectEntitlements | null>(null)
  const [isLoadingEntitlements, setIsLoadingEntitlements] = useState(false)
  const [modulesWithAccess, setModulesWithAccess] = useState<ModuleWithAccess[] | null>(null)
  const [isLoadingModules, setIsLoadingModules] = useState(false)
  const [modulesError, setModulesError] = useState<string | null>(null)

  const refreshWorkspaces = useCallback(async () => {
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
      const storedId = stored || null
      const validId =
        storedId && projects.some((workspace) => String(workspace.id) === storedId)
          ? storedId
          : projects[0] ? String(projects[0].id) : null
      setSelectedWorkspaceIdState(validId)
      if (validId) {
        localStorage.setItem(STORAGE_KEY, validId)
      } else {
        // Clear localStorage if no valid selection
        localStorage.removeItem(STORAGE_KEY)
      }
    } catch (err) {
      // On error, preserve existing selection if possible
      console.error('Failed to refresh workspaces:', err)
      // Only clear if we have no stored selection
      const stored = localStorage.getItem(STORAGE_KEY) || localStorage.getItem(OLD_STORAGE_KEY)
      if (!stored) {
        setWorkspaces([])
        setSelectedWorkspaceIdState(null)
      }
    }
  }, [])

  const refreshEntitlements = useCallback(async () => {
    if (!getAuthToken() || !selectedWorkspaceId) {
      setEntitlements(null)
      return
    }

    setIsLoadingEntitlements(true)
    try {
      // Convert string ID to number for API call (backend expects number)
      const entitlements = await subscriptionService.getProjectEntitlements(Number(selectedWorkspaceId))
      setEntitlements(entitlements)
    } catch (err) {
      setEntitlements(null)
    } finally {
      setIsLoadingEntitlements(false)
    }
  }, [selectedWorkspaceId])

  useEffect(() => {
    refreshWorkspaces()
  }, [refreshWorkspaces])

  const refreshModules = useCallback(async () => {
    if (!getAuthToken()) {
      setModulesWithAccess(null)
      setModulesError(null)
      return
    }

    if (!selectedWorkspaceId) {
      // No workspace selected - clear modules but don't show error
      setModulesWithAccess(null)
      setModulesError(null)
      return
    }

    setIsLoadingModules(true)
    setModulesError(null)
    try {
      // Convert string ID to number for API call (backend expects number)
      const modules = await moduleService.getProjectModules(Number(selectedWorkspaceId))
      
      // Handle empty or invalid response gracefully
      if (!Array.isArray(modules)) {
        console.warn('Modules API returned non-array response:', modules)
        setModulesWithAccess([])
        setModulesError(null)
        return
      }

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
      // Improved error handling with more specific messages
      // Try to provide fallback modules from platformRegistry if API fails
      let errorMessage = 'Failed to load modules'
      if (err instanceof Error) {
        // Check for specific error types
        if (err.message.includes('401') || err.message.includes('Unauthorized')) {
          errorMessage = 'Authentication required'
        } else if (err.message.includes('403') || err.message.includes('Forbidden')) {
          errorMessage = 'Access denied'
        } else if (err.message.includes('404') || err.message.includes('Not Found')) {
          errorMessage = 'Modules not found'
        } else if (err.message.includes('Network') || err.message.includes('fetch')) {
          errorMessage = 'Network error - please check your connection'
        } else if (err.message && err.message !== 'An error occurred' && err.message !== 'An unexpected error occurred') {
          // Use the actual error message if it's meaningful
          errorMessage = err.message
        }
      }
      
      // For non-critical errors (like network issues), don't show error but log it
      // Only show error for authentication/authorization issues
      const isCriticalError = errorMessage.includes('Authentication') || errorMessage.includes('Access denied')
      
      if (isCriticalError) {
        setModulesWithAccess(null)
        setModulesError(errorMessage)
      } else {
        // For non-critical errors, set empty array and don't show error
        // This allows the UI to still function with platformRegistry modules
        setModulesWithAccess([])
        setModulesError(null)
        // Log the error for debugging but don't show to user
        console.warn('Modules API failed, using fallback:', {
          error: err,
          workspaceId: selectedWorkspaceId,
          message: errorMessage,
        })
      }
    } finally {
      setIsLoadingModules(false)
    }
  }, [selectedWorkspaceId])

  useEffect(() => {
    refreshEntitlements()
    refreshModules()
  }, [refreshEntitlements, refreshModules])

  const setSelectedWorkspaceId = (workspaceId: string | number) => {
    // Always normalize to string for consistency
    const normalizedId = String(workspaceId)
    setSelectedWorkspaceIdState(normalizedId)
    localStorage.setItem(STORAGE_KEY, normalizedId)
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
      workspaces.find((workspace) => String(workspace.id) === selectedWorkspaceId) ?? null
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

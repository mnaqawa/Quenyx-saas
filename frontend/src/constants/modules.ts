// Single source of truth for all modules in the platform
// Defines module metadata: key, display name, status, routes, and access requirements

export type ModuleStatus = 'ready' | 'comingSoon'
export type ModuleKey = 'shieldobserve' | 'shieldrun' | 'shieldbalance' | 'shieldcore' | string

export interface ModuleConfig {
  key: ModuleKey
  displayName: string
  status: ModuleStatus
  basePath: (workspaceId: string | number) => string // Function to generate base path
  requiresWorkspace: boolean
  description?: string
}

// Module registry - all modules in the platform
export const moduleRegistry: ModuleConfig[] = [
  {
    key: 'shieldobserve',
    displayName: 'ShieldObserve',
    status: 'ready',
    basePath: (workspaceId) => `/app/workspaces/${workspaceId}/observe/real-time-monitoring`,
    requiresWorkspace: true,
    description: 'Real-time monitoring and infrastructure observability',
  },
  {
    key: 'shieldrun',
    displayName: 'ShieldRun',
    status: 'comingSoon',
    basePath: (workspaceId) => `/app/workspaces/${workspaceId}/shieldrun`,
    requiresWorkspace: true,
    description: 'Automated workflow execution and task scheduling',
  },
  {
    key: 'shieldbalance',
    displayName: 'ShieldBalance',
    status: 'comingSoon',
    basePath: (workspaceId) => `/app/workspaces/${workspaceId}/shieldbalance`,
    requiresWorkspace: true,
    description: 'Load balancing and traffic distribution',
  },
  {
    key: 'shieldcore',
    displayName: 'ShieldCore',
    status: 'comingSoon',
    basePath: (workspaceId) => `/app/workspaces/${workspaceId}/shieldcore`,
    requiresWorkspace: true,
    description: 'Core platform management and configuration',
  },
]

// Helper functions
export function getModuleByKey(key: string): ModuleConfig | undefined {
  return moduleRegistry.find((m) => m.key === key)
}

export function getReadyModules(): ModuleConfig[] {
  return moduleRegistry.filter((m) => m.status === 'ready')
}

export function getComingSoonModules(): ModuleConfig[] {
  return moduleRegistry.filter((m) => m.status === 'comingSoon')
}

export function isModuleReady(key: string): boolean {
  const module = getModuleByKey(key)
  return module?.status === 'ready'
}

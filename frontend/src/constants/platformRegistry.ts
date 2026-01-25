// Single source of truth for ALL platform navigation, routing, and module configuration
// This registry drives: sidebar rendering, router definitions, breadcrumbs, page titles,
// "Coming Soon / Locked" behavior, and future gateway API scoping

export type ModuleStatus = 'ready' | 'comingSoon'
export type ModuleKey = 
  | 'shieldobserve' 
  | 'shieldcore' 
  | 'shieldautomate' 
  | 'shieldbalance' 
  | 'shielddesk' 
  | 'shieldintegrations' 
  | 'shieldinventory' 
  | 'shieldknowledge' 
  | 'shieldnotify' 
  | 'shieldrespond' 
  | 'shieldsecure' 
  | 'shieldvoice' 
  | string

export interface RouteConfig {
  key: string // Unique identifier for the route
  label: string // Used for sidebar navigation
  path: string // Full route path (may contain :id, :moduleKey placeholders)
  title: string // PageHeader/breadcrumb title
  readyOnly?: boolean // If true, only shown when module is ready
}

export interface ModuleSidebarConfig {
  icon?: string // Optional icon identifier
  order: number // Display order in sidebar
  children?: RouteConfig[] // Nested routes (e.g., ShieldObserve subpages)
}

export interface ModuleConfig {
  key: ModuleKey
  displayName: string
  status: ModuleStatus
  requiresWorkspace: boolean
  baseRoutePattern: string // Route pattern (e.g., '/app/workspaces/:id/observe' or '/app/workspaces/:id/modules/:moduleKey')
  description?: string
  sidebar: ModuleSidebarConfig
}

// ShieldObserve routes configuration
const shieldObserveRoutes: RouteConfig[] = [
  {
    key: 'real-time-monitoring',
    label: 'Real-time Monitoring',
    path: '/app/workspaces/:id/observe/real-time-monitoring',
    title: 'Real-time Monitoring',
  },
  {
    key: 'infrastructure-map',
    label: 'Infrastructure Map',
    path: '/app/workspaces/:id/observe/infrastructure-map',
    title: 'Infrastructure Map',
  },
  {
    key: 'performance-analytics',
    label: 'Performance Analytics',
    path: '/app/workspaces/:id/observe/performance-analytics',
    title: 'Performance Analytics',
  },
  {
    key: 'capacity-planning',
    label: 'Capacity Planning',
    path: '/app/workspaces/:id/observe/capacity-planning',
    title: 'Capacity Planning',
  },
  {
    key: 'alert-management',
    label: 'Alert Management',
    path: '/app/workspaces/:id/observe/alert-management',
    title: 'Alert Management',
  },
  {
    key: 'instance-management',
    label: 'Instance Management',
    path: '/app/workspaces/:id/observe/instance-management',
    title: 'Instance Management',
  },
  {
    key: 'services',
    label: 'Services',
    path: '/app/workspaces/:id/observe/services',
    title: 'Services',
  },
  {
    key: 'reports',
    label: 'Reports',
    path: '/app/workspaces/:id/observe/reports',
    title: 'Reports',
  },
  {
    key: 'data-sources',
    label: 'Data Sources',
    path: '/app/workspaces/:id/observe/data-sources',
    title: 'Data Sources',
  },
]

// Module registry - all modules in the platform
// This should match the backend ModuleSeeder SHIELD_MODULES list
export const modules: ModuleConfig[] = [
  {
    key: 'shieldobserve',
    displayName: 'ShieldObserve',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/observe',
    description: 'Real-time infrastructure monitoring and performance insights across your environment.',
    sidebar: {
      order: 1,
      children: shieldObserveRoutes,
    },
  },
  {
    key: 'shieldcore',
    displayName: 'ShieldCore',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Central configuration and governance hub for platform control and policy management.',
    sidebar: {
      order: 2,
    },
  },
  {
    key: 'shieldautomate',
    displayName: 'ShieldAutomate',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Workflow automation and process orchestration across systems and teams.',
    sidebar: {
      order: 3,
    },
  },
  {
    key: 'shieldbalance',
    displayName: 'ShieldBalance',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Load balancing and traffic management for optimal resource distribution.',
    sidebar: {
      order: 4,
    },
  },
  {
    key: 'shielddesk',
    displayName: 'ShieldDesk',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Help desk operations for ticketing, SLA compliance, and customer satisfaction.',
    sidebar: {
      order: 5,
    },
  },
  {
    key: 'shieldintegrations',
    displayName: 'ShieldIntegrations',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Third-party integrations and API connections for external services.',
    sidebar: {
      order: 6,
    },
  },
  {
    key: 'shieldinventory',
    displayName: 'ShieldInventory',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Comprehensive asset discovery, inventory management, and automated health tracking.',
    sidebar: {
      order: 7,
    },
  },
  {
    key: 'shieldknowledge',
    displayName: 'ShieldKnowledge',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Knowledge management for documentation, playbooks, and operational procedures.',
    sidebar: {
      order: 8,
    },
  },
  {
    key: 'shieldnotify',
    displayName: 'ShieldNotify',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Alert and notification management across email, SMS, and in-app channels.',
    sidebar: {
      order: 9,
    },
  },
  {
    key: 'shieldrespond',
    displayName: 'ShieldRespond',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Automated incident response and orchestration for rapid recovery and resolution.',
    sidebar: {
      order: 10,
    },
  },
  {
    key: 'shieldsecure',
    displayName: 'ShieldSecure',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Security operations center for threat monitoring, vulnerability scanning, and posture defense.',
    sidebar: {
      order: 11,
    },
  },
  {
    key: 'shieldvoice',
    displayName: 'ShieldVoice',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'AI voice and IVR operations for automated customer support and service analytics.',
    sidebar: {
      order: 12,
    },
  },
]

// Routes by module key for quick lookup
export const routesByModule: Record<string, RouteConfig[]> = {
  shieldobserve: shieldObserveRoutes,
  // Other modules don't have nested routes yet
}

// Helper: Get module by key
export function getModule(key: string): ModuleConfig | undefined {
  return modules.find((m) => m.key === key)
}

// Helper: Get module base path (replaces :id and :moduleKey with actual values)
export function getModuleBasePath(key: string, workspaceId: string | number): string {
  const module = getModule(key)
  if (!module) return '#'

  let path = module.baseRoutePattern
  path = path.replace(':id', String(workspaceId))
  path = path.replace(':moduleKey', key)

  return path
}

// Helper: Get page title from pathname
export function getPageTitleFromPath(pathname: string): string {
  // Try to match against known routes
  for (const module of modules) {
    if (module.sidebar.children) {
      for (const route of module.sidebar.children) {
        // Replace :id with a pattern matcher
        const routePattern = route.path.replace(':id', '[^/]+')
        const regex = new RegExp(`^${routePattern}$`)
        if (regex.test(pathname)) {
          return route.title
        }
      }
    }
  }

  // Fallback: extract from pathname or return module display name
  const pathParts = pathname.split('/').filter(Boolean)
  if (pathParts.length > 0) {
    const lastPart = pathParts[pathParts.length - 1]
    // Convert kebab-case to Title Case
    return lastPart
      .split('-')
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ')
  }

  return 'PortShield'
}

// Helper: Check if module is ready
export function isModuleReady(key: string): boolean {
  const module = getModule(key)
  return module?.status === 'ready' || false
}

// Helper: Check if module is locked (non-UI helper)
export function isModuleLocked(key: string, allowedByKey: Record<string, boolean>): boolean {
  return !allowedByKey[key]
}

// Helper: Get routes for a module
export function getModuleRoutes(key: string): RouteConfig[] {
  return routesByModule[key] || []
}

// Backward compatibility: Re-export as moduleRegistry for existing code
export const moduleRegistry = modules

// Backward compatibility: Re-export helpers
export function getModuleByKey(key: string): ModuleConfig | undefined {
  return getModule(key)
}

export function getReadyModules(): ModuleConfig[] {
  return modules.filter((m) => m.status === 'ready')
}

export function getComingSoonModules(): ModuleConfig[] {
  return modules.filter((m) => m.status === 'comingSoon')
}

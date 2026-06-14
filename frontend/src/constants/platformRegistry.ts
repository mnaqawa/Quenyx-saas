// Single source of truth for ALL platform navigation, routing, and module configuration
// This registry drives: sidebar rendering, router definitions, breadcrumbs, page titles,
// "Coming Soon / Locked" behavior, and future gateway API scoping

export type ModuleStatus = 'ready' | 'comingSoon'
export type ModuleKey =
  | 'qynsight'
  | 'qyncore'
  | 'qynrun'
  | 'qynbalance'
  | 'qynsupport'
  | 'qynintegrations'
  | 'qynasset'
  | 'qynknow'
  | 'qynnotify'
  | 'qynreact'
  | 'qynshield'
  | 'qynva'
  | string

export interface RouteConfig {
  key: string
  label: string
  path: string
  title: string
  readyOnly?: boolean
}

export interface ModuleSidebarConfig {
  icon?: string
  order: number
  children?: RouteConfig[]
}

export interface ModuleConfig {
  key: ModuleKey
  displayName: string
  status: ModuleStatus
  requiresWorkspace: boolean
  baseRoutePattern: string
  description?: string
  sidebar: ModuleSidebarConfig
}

// QynSight (monitoring) routes configuration
const qynSightRoutes: RouteConfig[] = [
  { key: 'real-time-monitoring', label: 'Real-time Monitoring', path: '/app/workspaces/:id/observe/real-time-monitoring', title: 'Real-time Monitoring' },
  { key: 'infrastructure-map', label: 'Infrastructure Map', path: '/app/workspaces/:id/observe/infrastructure-map', title: 'Infrastructure Map' },
  { key: 'performance-analytics', label: 'Performance Analytics', path: '/app/workspaces/:id/observe/performance-analytics', title: 'Performance Analytics' },
  { key: 'capacity-planning', label: 'Capacity Planning', path: '/app/workspaces/:id/observe/capacity-planning', title: 'Capacity Planning' },
  { key: 'alert-management', label: 'Alert Management', path: '/app/workspaces/:id/observe/alert-management', title: 'Alert Management' },
  { key: 'instance-management', label: 'Instance Management', path: '/app/workspaces/:id/observe/instance-management', title: 'Instance Management' },
  { key: 'services', label: 'Services', path: '/app/workspaces/:id/observe/services', title: 'Services' },
  { key: 'reports', label: 'Reports', path: '/app/workspaces/:id/observe/reports', title: 'Reports' },
  { key: 'data-sources', label: 'Data Sources', path: '/app/workspaces/:id/observe/data-sources', title: 'Data Sources' },
  { key: 'targets', label: 'Targets', path: '/app/workspaces/:id/observe/targets', title: 'Monitored Targets' },
]

// TEMPORARY: while QynSight is the only module under active development, hide every
// other module across navigation, subscriptions, and workspace settings.
export const HIDE_NON_QYNSIGHT_MODULES = true
export const ACTIVE_MODULE_KEYS = ['qynsight']

export function isModuleTemporarilyVisible(key: string): boolean {
  return !HIDE_NON_QYNSIGHT_MODULES || ACTIVE_MODULE_KEYS.includes(key)
}

export const modules: ModuleConfig[] = [
  {
    key: 'qynsight',
    displayName: 'QynSight',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/observe',
    description: 'Real-time infrastructure monitoring and performance insights across your environment.',
    sidebar: { order: 1, children: qynSightRoutes },
  },
  {
    key: 'qyncore',
    displayName: 'QynCore',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Central configuration and governance hub for platform control and policy management.',
    sidebar: { order: 2 },
  },
  {
    key: 'qynrun',
    displayName: 'QynRun',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Workflow automation and process orchestration across systems and teams.',
    sidebar: { order: 3 },
  },
  {
    key: 'qynbalance',
    displayName: 'QynBalance',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Load balancing and traffic management for optimal resource distribution.',
    sidebar: { order: 4 },
  },
  {
    key: 'qynsupport',
    displayName: 'QynSupport',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Help desk operations for ticketing, SLA compliance, and customer satisfaction.',
    sidebar: { order: 5 },
  },
  {
    key: 'qynintegrations',
    displayName: 'QynIntegrations',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Third-party integrations and API connections for external services.',
    sidebar: { order: 6 },
  },
  {
    key: 'qynasset',
    displayName: 'QynAsset',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Comprehensive asset discovery, inventory management, and automated health tracking.',
    sidebar: { order: 7 },
  },
  {
    key: 'qynknow',
    displayName: 'QynKnow',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Knowledge management for documentation, playbooks, and operational procedures.',
    sidebar: { order: 8 },
  },
  {
    key: 'qynnotify',
    displayName: 'QynNotify',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Alert and notification management across email, SMS, and in-app channels.',
    sidebar: { order: 9 },
  },
  {
    key: 'qynreact',
    displayName: 'QynReact',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Automated incident response and orchestration for rapid recovery and resolution.',
    sidebar: { order: 10 },
  },
  {
    key: 'qynshield',
    displayName: 'QynShield',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Security operations center for threat monitoring, vulnerability scanning, and posture defense.',
    sidebar: { order: 11 },
  },
  {
    key: 'qynva',
    displayName: 'QynVA',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'AI voice and IVR operations for automated customer support and service analytics.',
    sidebar: { order: 12 },
  },
]

export const routesByModule: Record<string, RouteConfig[]> = {
  qynsight: qynSightRoutes,
}

export function getModule(key: string): ModuleConfig | undefined {
  return modules.find((m) => m.key === key)
}

export function getModuleBasePath(key: string, workspaceId: string | number): string {
  const module = getModule(key)
  if (!module) return '#'
  let path = module.baseRoutePattern
  path = path.replace(':id', String(workspaceId))
  path = path.replace(':moduleKey', key)
  return path
}

export function getPageTitleFromPath(pathname: string): string {
  for (const module of modules) {
    if (module.sidebar.children) {
      for (const route of module.sidebar.children) {
        const routePattern = route.path.replace(':id', '[^/]+')
        const regex = new RegExp(`^${routePattern}$`)
        if (regex.test(pathname)) return route.title
      }
    }
  }
  const pathParts = pathname.split('/').filter(Boolean)
  if (pathParts.length > 0) {
    const lastPart = pathParts[pathParts.length - 1]
    return lastPart
      .split('-')
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ')
  }
  return 'Quenyx vOPS HUB'
}

export function isModuleReady(key: string): boolean {
  const module = getModule(key)
  return module?.status === 'ready' || false
}

export function isModuleLocked(key: string, allowedByKey: Record<string, boolean>): boolean {
  return !allowedByKey[key]
}

export function getModuleRoutes(key: string): RouteConfig[] {
  return routesByModule[key] || []
}

export const moduleRegistry = modules
export function getModuleByKey(key: string): ModuleConfig | undefined {
  return getModule(key)
}
export function getReadyModules(): ModuleConfig[] {
  return modules.filter((m) => m.status === 'ready')
}
export function getComingSoonModules(): ModuleConfig[] {
  return modules.filter((m) => m.status === 'comingSoon')
}

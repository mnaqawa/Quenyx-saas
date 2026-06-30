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
  // 'qynintegrations' is intentionally NOT a business module. Integrations is a platform
  // capability/page for EXTERNAL systems only. The key is retained here (and as a backend
  // entitlement key) purely for backward compatibility with existing plans/subscriptions.
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
  hidden?: boolean
  i18nKey?: string
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

// QynSight production menu (Instance Management hidden until host operations ship)
const qynSightRoutes: RouteConfig[] = [
  { key: 'overview', label: 'Overview', i18nKey: 'nav.qynsight.overview', path: '/app/workspaces/:id/observe/overview', title: 'Overview' },
  { key: 'operations-intelligence', label: 'Operations Intelligence', i18nKey: 'nav.qynsight.operationsIntelligence', path: '/app/workspaces/:id/observe/operations-intelligence', title: 'Operations Intelligence' },
  { key: 'real-time-monitoring', label: 'Real-time Monitoring', i18nKey: 'nav.qynsight.realTimeMonitoring', path: '/app/workspaces/:id/observe/real-time-monitoring', title: 'Real-time Monitoring' },
  { key: 'infrastructure-map', label: 'Infrastructure Map', i18nKey: 'nav.qynsight.infrastructureMap', path: '/app/workspaces/:id/observe/infrastructure-map', title: 'Infrastructure Map' },
  { key: 'performance-analytics', label: 'Performance Analytics', i18nKey: 'nav.qynsight.performanceAnalytics', path: '/app/workspaces/:id/observe/performance-analytics', title: 'Performance Analytics' },
  { key: 'capacity-planning', label: 'Capacity Planning', i18nKey: 'nav.qynsight.capacityPlanning', path: '/app/workspaces/:id/observe/capacity-planning', title: 'Capacity Planning' },
  { key: 'alert-management', label: 'Alert Management', i18nKey: 'nav.qynsight.alertManagement', path: '/app/workspaces/:id/observe/alert-management', title: 'Alert Management' },
  { key: 'services', label: 'Service Checks', i18nKey: 'nav.qynsight.services', path: '/app/workspaces/:id/observe/services', title: 'Service Checks' },
  { key: 'targets', label: 'Hosts', i18nKey: 'nav.qynsight.targets', path: '/app/workspaces/:id/observe/targets', title: 'Hosts' },
  { key: 'instance-management', label: 'Instance Management', i18nKey: 'nav.qynsight.instanceManagement', path: '/app/workspaces/:id/observe/instance-management', title: 'Instance Management', hidden: true },
]

// Sprint 25 (v1.0 GA): the temporary "QynSight-only" sidebar gate has been REMOVED. Every customer
// business module is now enabled across navigation, subscriptions, and workspace settings. QynCore and
// the legacy `qynintegrations` entitlement key remain PLATFORM-ONLY (never navigable business modules):
// QynCore backs billing/governance surfaces, and Integrations is a platform page for external systems.
export const HIDE_NON_QYNSIGHT_MODULES = false

/** Keys that are platform capabilities, not customer-facing business modules — never shown in the module nav. */
const PLATFORM_ONLY_MODULE_KEYS = ['qyncore', 'qynintegrations']

// Retained for backward compatibility with callers that reference it; with the gate removed it lists all
// business module keys (used only for staged rollouts in the past).
export const ACTIVE_MODULE_KEYS = [
  'qynsight', 'qynasset', 'qynrun', 'qynreact', 'qynknow',
  'qynsupport', 'qynnotify', 'qynshield', 'qynbalance', 'qynva',
]

export function isModuleTemporarilyVisible(key: string): boolean {
  if (PLATFORM_ONLY_MODULE_KEYS.includes(key)) return false
  return true
}

/**
 * GA remediation: navigation-only visibility. A module is shown in the sidebar
 * ONLY when it is a customer-facing business module AND it is production-ready
 * (status !== 'comingSoon'). This prevents exposing placeholder/"Coming Soon"
 * pages in navigation — e.g. QynShield, whose SPA surface is not yet shipped,
 * is hidden until it is production-ready, without removing its entitlement key.
 */
export function isModuleNavigable(key: string): boolean {
  if (!isModuleTemporarilyVisible(key)) return false
  const config = modules.find((m) => m.key === key)
  return config ? config.status !== 'comingSoon' : false
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
    // QynCore is the PLATFORM CORE (billing, subscriptions, configuration and governance), not a
    // customer-facing business module. It is retained here only to back the platform-core surfaces
    // that already depend on the `qyncore` key (e.g. the /qyncore/billing route and the Subscriptions
    // page). It stays hidden from the business-module navigation via HIDE_NON_QYNSIGHT_MODULES.
    key: 'qyncore',
    displayName: 'QynCore',
    status: 'comingSoon',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/modules/:moduleKey',
    description: 'Platform core: billing, subscriptions, configuration, and governance (not a navigable business module).',
    sidebar: { order: 2 },
  },
  {
    key: 'qynrun',
    displayName: 'QynRun',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynrun/automation',
    description: 'Workflow automation and process orchestration across systems and teams.',
    sidebar: { order: 3 },
  },
  {
    key: 'qynbalance',
    displayName: 'QynBalance',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynbalance/cost',
    description: 'Enterprise Cost Intelligence: infrastructure cost, optimization, and budget forecasting from real data.',
    sidebar: { order: 4 },
  },
  {
    key: 'qynsupport',
    displayName: 'QynSupport',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynsupport/tickets',
    description: 'Help desk operations for ticketing, SLA compliance, and customer satisfaction.',
    sidebar: { order: 5 },
  },
  // NOTE (v1.0.0): QynIntegrations has been removed as a business module. Integrations is a
  // platform capability/page for EXTERNAL systems only (top-level `/integrations` route), NOT a
  // Quenyx business module and NOT used for internal module-to-module communication (that is handled
  // by QynCore platform services). The `qynintegrations` entitlement key still exists on the backend
  // (plans + gateway gate for /integrations*) for backward compatibility — see EntitlementService.
  {
    key: 'qynasset',
    displayName: 'QynAsset',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynasset/intelligence',
    description: 'Comprehensive asset discovery, inventory management, and automated health tracking.',
    sidebar: { order: 7 },
  },
  {
    key: 'qynknow',
    displayName: 'QynKnow',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynknow/knowledge',
    description: 'Knowledge management for documentation, playbooks, and operational procedures.',
    sidebar: { order: 8 },
  },
  {
    key: 'qynnotify',
    displayName: 'QynNotify',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynnotify/notifications',
    description: 'Alert and notification management across email, SMS, and in-app channels.',
    sidebar: { order: 9 },
  },
  {
    key: 'qynreact',
    displayName: 'QynReact',
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynreact/incidents',
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
    status: 'ready',
    requiresWorkspace: true,
    baseRoutePattern: '/app/workspaces/:id/qynva/operator',
    description: 'Enterprise AI Operator: discovers capabilities, builds context, reasons, and coordinates cross-module plans.',
    sidebar: { order: 12 },
  },
]

// Child routes per module. The sidebar links to the FIRST route of each ready module; additional routes
// are reached via in-page navigation. QynShield has no SPA surface yet (remains a placeholder).
const qynAssetRoutes: RouteConfig[] = [
  { key: 'intelligence', label: 'Asset Intelligence', i18nKey: 'nav.qynasset.assetIntelligence', path: '/app/workspaces/:id/qynasset/intelligence', title: 'Asset Intelligence' },
]
const qynRunRoutes: RouteConfig[] = [
  { key: 'automation', label: 'Automation', i18nKey: 'nav.qynrun.automation', path: '/app/workspaces/:id/qynrun/automation', title: 'Automation' },
]
const qynReactRoutes: RouteConfig[] = [
  { key: 'incidents', label: 'Incident Workspace', i18nKey: 'nav.qynreact.incidents', path: '/app/workspaces/:id/qynreact/incidents', title: 'Incident Workspace' },
]
const qynKnowRoutes: RouteConfig[] = [
  { key: 'knowledge', label: 'Knowledge Center', i18nKey: 'nav.qynknow.knowledge', path: '/app/workspaces/:id/qynknow/knowledge', title: 'Knowledge Center' },
  { key: 'search', label: 'Enterprise Search', i18nKey: 'nav.qynknow.search', path: '/app/workspaces/:id/qynknow/search', title: 'Enterprise Search' },
  { key: 'timeline', label: 'Global Timeline', i18nKey: 'nav.qynknow.timeline', path: '/app/workspaces/:id/qynknow/timeline', title: 'Global Timeline' },
]
const qynSupportRoutes: RouteConfig[] = [
  { key: 'tickets', label: 'Service Desk', i18nKey: 'nav.qynsupport.tickets', path: '/app/workspaces/:id/qynsupport/tickets', title: 'Service Desk' },
]
const qynNotifyRoutes: RouteConfig[] = [
  { key: 'notifications', label: 'Notification Center', i18nKey: 'nav.qynnotify.notifications', path: '/app/workspaces/:id/qynnotify/notifications', title: 'Notification Center' },
]
const qynBalanceRoutes: RouteConfig[] = [
  { key: 'cost', label: 'Cost Intelligence', i18nKey: 'nav.qynbalance.cost', path: '/app/workspaces/:id/qynbalance/cost', title: 'Cost Intelligence' },
]
const qynVaRoutes: RouteConfig[] = [
  { key: 'operator', label: 'Operator', i18nKey: 'nav.qynva.operator', path: '/app/workspaces/:id/qynva/operator', title: 'Enterprise AI Operator' },
  { key: 'executive', label: 'Executive Intelligence', i18nKey: 'nav.qynva.executive', path: '/app/workspaces/:id/qynva/executive', title: 'Executive Intelligence' },
  { key: 'analytics', label: 'Enterprise Analytics', i18nKey: 'nav.qynva.analytics', path: '/app/workspaces/:id/qynva/analytics', title: 'Enterprise Analytics' },
  { key: 'health', label: 'Platform Health', i18nKey: 'nav.qynva.health', path: '/app/workspaces/:id/qynva/health', title: 'Platform Health' },
]

export const routesByModule: Record<string, RouteConfig[]> = {
  qynsight: qynSightRoutes,
  qynasset: qynAssetRoutes,
  qynrun: qynRunRoutes,
  qynreact: qynReactRoutes,
  qynknow: qynKnowRoutes,
  qynsupport: qynSupportRoutes,
  qynnotify: qynNotifyRoutes,
  qynbalance: qynBalanceRoutes,
  qynva: qynVaRoutes,
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

/** Resolve route config (incl. i18nKey) for breadcrumb labels. */
export function getRouteConfigFromPath(pathname: string): RouteConfig | null {
  for (const module of modules) {
    if (module.sidebar.children) {
      for (const route of module.sidebar.children) {
        const routePattern = route.path.replace(':id', '[^/]+')
        const regex = new RegExp(`^${routePattern}$`)
        if (regex.test(pathname)) return route
      }
    }
  }
  return null
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

export function getVisibleModuleRoutes(key: string): RouteConfig[] {
  return (routesByModule[key] || []).filter((r) => !r.hidden)
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

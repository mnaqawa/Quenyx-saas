// Single source of truth for ShieldObserve page routes and titles
// Used for navigation, breadcrumbs, and ensuring consistency

export interface ObserveRouteConfig {
  route: string
  title: string
  label: string // For navigation (can be same as title or shorter)
}

export const observeRoutes: ObserveRouteConfig[] = [
  { route: 'real-time-monitoring', title: 'Real-time Monitoring', label: 'Real-time Monitoring' },
  { route: 'infrastructure-map', title: 'Infrastructure Map', label: 'Infrastructure Map' },
  { route: 'performance-analytics', title: 'Performance Analytics', label: 'Performance Analytics' },
  { route: 'capacity-planning', title: 'Capacity Planning', label: 'Capacity Planning' },
  { route: 'alert-management', title: 'Alert Management', label: 'Alert Management' },
  { route: 'instance-management', title: 'Instance Management', label: 'Instance Management' },
  { route: 'services', title: 'Services', label: 'Services' },
  { route: 'reports', title: 'Reports', label: 'Reports' },
  { route: 'data-sources', title: 'Data Sources', label: 'Data Sources' },
]

// Map for quick lookup by route
export const observeRoutesMap: Record<string, string> = Object.fromEntries(
  observeRoutes.map((config) => [config.route, config.title])
)

// Get title by route
export function getObservePageTitle(route: string): string {
  return observeRoutesMap[route] || 'ShieldObserve'
}

// DEPRECATED: This file is kept for backward compatibility
// All routes are now defined in platformRegistry.ts
// Re-export from platformRegistry to maintain existing imports

import { routesByModule } from './platformRegistry'

// Backward compatibility: Map RouteConfig to ObserveRouteConfig format
export interface ObserveRouteConfig {
  route: string
  title: string
  label: string
}

// Convert RouteConfig[] to ObserveRouteConfig[] format
function convertToObserveRoutes(routes: typeof routesByModule.shieldobserve): ObserveRouteConfig[] {
  return routes.map((route) => ({
    route: route.key,
    title: route.title,
    label: route.label,
  }))
}

export const observeRoutes: ObserveRouteConfig[] = convertToObserveRoutes(routesByModule.shieldobserve || [])

// Map for quick lookup by route
export const observeRoutesMap: Record<string, string> = Object.fromEntries(
  observeRoutes.map((config) => [config.route, config.title])
)

// Get title by route (now uses platformRegistry)
export function getObservePageTitle(route: string): string {
  // Extract route key from full path or use route directly
  const routeConfig = routesByModule.shieldobserve?.find((r) => r.key === route)
  return routeConfig?.title || 'ShieldObserve'
}

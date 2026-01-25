// DEPRECATED: This file is kept for backward compatibility
// All modules are now defined in platformRegistry.ts
// Re-export from platformRegistry to maintain existing imports

import {
  type ModuleStatus,
  type ModuleKey,
  type ModuleConfig,
  moduleRegistry,
  getModuleByKey as getModuleByKeyFromRegistry,
  getReadyModules as getReadyModulesFromRegistry,
  getComingSoonModules as getComingSoonModulesFromRegistry,
  isModuleReady as isModuleReadyFromRegistry,
  getModuleBasePath,
} from './platformRegistry'

// Re-export types
export type { ModuleStatus, ModuleKey, ModuleConfig }

// Re-export registry
export { moduleRegistry }

// Re-export helpers (with backward compatibility wrapper for basePath)
export function getModuleByKey(key: string): ModuleConfig | undefined {
  const module = getModuleByKeyFromRegistry(key)
  if (!module) return undefined

  // Create backward-compatible ModuleConfig with basePath function
  return {
    ...module,
    basePath: (workspaceId: string | number) => getModuleBasePath(key, workspaceId),
  } as ModuleConfig & { basePath: (workspaceId: string | number) => string }
}

export function getReadyModules(): ModuleConfig[] {
  return getReadyModulesFromRegistry()
}

export function getComingSoonModules(): ModuleConfig[] {
  return getComingSoonModulesFromRegistry()
}

export function isModuleReady(key: string): boolean {
  return isModuleReadyFromRegistry(key)
}

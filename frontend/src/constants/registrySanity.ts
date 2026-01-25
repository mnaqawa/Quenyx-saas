// Registry validation - ensures platform registry integrity
// Run in development mode to catch configuration errors early

import { modules } from './platformRegistry'

export interface ValidationError {
  type: 'duplicate_module_key' | 'duplicate_route_path' | 'invalid_observe_route'
  message: string
  details?: unknown
}

export function validatePlatformRegistry(): ValidationError[] {
  const errors: ValidationError[] = []

  // Check for duplicate module keys
  const moduleKeys = new Set<string>()
  for (const module of modules) {
    if (moduleKeys.has(module.key)) {
      errors.push({
        type: 'duplicate_module_key',
        message: `Duplicate module key found: ${module.key}`,
        details: { key: module.key },
      })
    }
    moduleKeys.add(module.key)
  }

  // Check for duplicate route paths within each module
  for (const module of modules) {
    if (module.sidebar.children) {
      const routePaths = new Set<string>()
      for (const route of module.sidebar.children) {
        // Normalize path by removing :id placeholder for comparison
        const normalizedPath = route.path.replace(':id', 'WORKSPACE_ID')
        if (routePaths.has(normalizedPath)) {
          errors.push({
            type: 'duplicate_route_path',
            message: `Duplicate route path in module ${module.key}: ${route.path}`,
            details: { module: module.key, path: route.path },
          })
        }
        routePaths.add(normalizedPath)
      }
    }
  }

  // Check that all ShieldObserve routes start with /app/workspaces/:id/observe
  const observeModule = modules.find((m) => m.key === 'shieldobserve')
  if (observeModule?.sidebar.children) {
    for (const route of observeModule.sidebar.children) {
      if (!route.path.startsWith('/app/workspaces/:id/observe')) {
        errors.push({
          type: 'invalid_observe_route',
          message: `ShieldObserve route must start with /app/workspaces/:id/observe: ${route.path}`,
          details: { route: route.path },
        })
      }
    }
  }

  return errors
}

// Development-only validation (call at app startup)
export function validateRegistryInDevelopment(): void {
  if (import.meta.env.DEV) {
    const errors = validatePlatformRegistry()
    if (errors.length > 0) {
      console.error('❌ Platform Registry Validation Failed:')
      errors.forEach((error) => {
        console.error(`  [${error.type}] ${error.message}`, error.details || '')
      })
      throw new Error(`Platform registry validation failed with ${errors.length} error(s)`)
    } else {
      console.log('✅ Platform Registry Validation Passed')
    }
  }
}

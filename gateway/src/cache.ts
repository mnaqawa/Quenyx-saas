import crypto from 'crypto'

interface CachedEntitlement {
  modules_allowed: string[]
  expiresAt: number
}

interface CacheEntry {
  entitlements: CachedEntitlement
}

// In-memory cache: key = hash(token + projectId), value = cached entitlements
const cache = new Map<string, CacheEntry>()

const CACHE_TTL_MS = parseInt(process.env.ENTITLEMENTS_CACHE_TTL_MS || '30000', 10)

/**
 * Generate cache key from token and project ID
 */
export function getCacheKey(token: string, projectId: number): string {
  const combined = `${token}:${projectId}`
  return crypto.createHash('sha256').update(combined).digest('hex')
}

/**
 * Hash token for logging (first 8 chars of SHA-256)
 */
export function hashToken(token: string): string {
  return crypto.createHash('sha256').update(token).digest('hex').substring(0, 8)
}

/**
 * Get cached entitlements if available and not expired
 */
export function getCachedEntitlements(cacheKey: string): string[] | null {
  const cached = cache.get(cacheKey)
  const now = Date.now()
  
  if (cached && cached.entitlements.expiresAt > now) {
    return cached.entitlements.modules_allowed
  }
  
  return null
}

/**
 * Store entitlements in cache
 */
export function setCachedEntitlements(cacheKey: string, modulesAllowed: string[]): void {
  const expiresAt = Date.now() + CACHE_TTL_MS
  cache.set(cacheKey, {
    entitlements: {
      modules_allowed: modulesAllowed,
      expiresAt,
    },
  })
}

/**
 * Clear the entitlements cache
 */
export function clearCache(): void {
  cache.clear()
}

/**
 * Get cache stats (for debugging)
 */
export function getCacheStats(): { size: number; validEntries: number } {
  const now = Date.now()
  let validEntries = 0
  
  for (const entry of cache.values()) {
    if (entry.entitlements.expiresAt > now) {
      validEntries++
    }
  }
  
  return {
    size: cache.size,
    validEntries,
  }
}

import { Request, Response, NextFunction } from 'express'
import { getCachedEntitlements, setCachedEntitlements, hashToken } from './cache'

const BACKEND_BASE_URL = process.env.BACKEND_BASE_URL || 'http://127.0.0.1:8000'

interface EntitlementsResponse {
  success: boolean
  data?: {
    modules_allowed?: string[]
  }
}

function getUserCacheKey(token: string): string {
  return `user:${token}`
}

/**
 * Fetch user-profile entitlements from backend (not workspace subscription).
 */
async function fetchUserEntitlements(token: string): Promise<string[]> {
  const url = `${BACKEND_BASE_URL}/api/auth/entitlements`

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    if (response.status === 401) {
      throw new Error('Unauthorized')
    }
    throw new Error(`Backend returned ${response.status}`)
  }

  const data = (await response.json()) as EntitlementsResponse

  if (!data.success || !data.data || !Array.isArray(data.data.modules_allowed)) {
    throw new Error('Invalid entitlements response format')
  }

  return data.data.modules_allowed
}

/**
 * Check if the authenticated user has a module on their profile plan.
 */
async function checkUserEntitlement(token: string, requiredModule: string): Promise<boolean> {
  const cacheKey = getUserCacheKey(token)

  const cached = getCachedEntitlements(cacheKey)
  if (cached !== null) {
    return cached.includes(requiredModule)
  }

  const modulesAllowed = await fetchUserEntitlements(token)
  setCachedEntitlements(cacheKey, modulesAllowed)

  return modulesAllowed.includes(requiredModule)
}

/**
 * Entitlement enforcement middleware
 *
 * ENFORCED ROUTES (require qynintegrations on user profile plan):
 * - /api/projects/:projectId/integrations*
 * - /api/workspaces/:projectId/integrations* (alias)
 */
export async function enforceEntitlements(
  req: Request,
  res: Response,
  next: NextFunction
): Promise<void> {
  const path = req.path

  const isIntegrationsRoute =
    /^\/api\/projects\/\d+\/integrations/.test(path) ||
    /^\/api\/workspaces\/\d+\/integrations/.test(path)

  if (isIntegrationsRoute) {
    const authHeader = req.headers.authorization

    if (!authHeader || !authHeader.startsWith('Bearer ')) {
      res.status(401).json({
        success: false,
        message: 'Authentication required',
      })
      return
    }

    const token = authHeader.substring(7)
    const tokenHash = hashToken(token)
    const requiredModule = 'qynintegrations'

    try {
      const hasAccess = await checkUserEntitlement(token, requiredModule)

      if (!hasAccess) {
        console.log(`DENIED: token=${tokenHash} module=${requiredModule} (user profile)`)
        res.status(403).json({
          success: false,
          message: 'Your current plan does not allow access to this module',
        })
        return
      }

      console.log(`ALLOWED: token=${tokenHash} module=${requiredModule} (user profile)`)
    } catch (error) {
      console.error(`Entitlement check failed: ${error instanceof Error ? error.message : 'Unknown error'}`)
      res.status(500).json({
        success: false,
        message: 'Failed to verify access permissions',
      })
      return
    }
  }

  next()
}

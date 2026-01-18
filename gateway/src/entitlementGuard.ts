import { Request, Response, NextFunction } from 'express'
import { getCacheKey, getCachedEntitlements, setCachedEntitlements, hashToken } from './cache'

const BACKEND_BASE_URL = process.env.BACKEND_BASE_URL || 'http://127.0.0.1:8000'

/**
 * Extract project ID from URL patterns like /api/projects/:projectId/...
 */
export function extractProjectId(path: string): number | null {
  const match = path.match(/^\/api\/projects\/(\d+)/)
  return match ? parseInt(match[1], 10) : null
}

/**
 * Fetch entitlements from backend
 */
async function fetchEntitlements(token: string, projectId: number): Promise<string[]> {
  const url = `${BACKEND_BASE_URL}/api/projects/${projectId}/entitlements`
  
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json',
    },
  })
  
  if (!response.ok) {
    if (response.status === 401) {
      throw new Error('Unauthorized')
    }
    if (response.status === 404) {
      throw new Error('Project not found')
    }
    throw new Error(`Backend returned ${response.status}`)
  }
  
  const data = await response.json()
  
  if (!data.success || !data.data || !Array.isArray(data.data.modules_allowed)) {
    throw new Error('Invalid entitlements response format')
  }
  
  return data.data.modules_allowed as string[]
}

/**
 * Check if user has access to a specific module for a project
 */
async function checkEntitlement(
  token: string,
  projectId: number,
  requiredModule: string
): Promise<boolean> {
  const cacheKey = getCacheKey(token, projectId)
  
  // Check cache first
  const cached = getCachedEntitlements(cacheKey)
  if (cached !== null) {
    return cached.includes(requiredModule)
  }
  
  // Fetch from backend
  const modulesAllowed = await fetchEntitlements(token, projectId)
  
  // Update cache
  setCachedEntitlements(cacheKey, modulesAllowed)
  
  return modulesAllowed.includes(requiredModule)
}

/**
 * Entitlement enforcement middleware
 */
export async function enforceEntitlements(
  req: Request,
  res: Response,
  next: NextFunction
): Promise<void> {
  const path = req.path
  
  // Check if this is a project-scoped integrations route
  if (path.match(/^\/api\/projects\/\d+\/integrations/)) {
    const projectId = extractProjectId(path)
    const authHeader = req.headers.authorization
    
    if (!projectId) {
      res.status(400).json({
        success: false,
        message: 'Invalid project ID in URL',
      })
      return
    }
    
    if (!authHeader || !authHeader.startsWith('Bearer ')) {
      res.status(401).json({
        success: false,
        message: 'Authentication required',
      })
      return
    }
    
    const token = authHeader.substring(7)
    const tokenHash = hashToken(token)
    const requiredModule = 'shieldintegrations'
    
    try {
      const hasAccess = await checkEntitlement(token, projectId, requiredModule)
      
      if (!hasAccess) {
        console.log(`DENIED: token=${tokenHash} project=${projectId} module=${requiredModule}`)
        res.status(403).json({
          success: false,
          message: 'Your current plan does not allow access to this module',
        })
        return
      }
      
      console.log(`ALLOWED: token=${tokenHash} project=${projectId} module=${requiredModule}`)
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

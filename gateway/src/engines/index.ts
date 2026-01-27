import express, { Request, Response, Router } from 'express'
import { getNagiosServices, getNagiosSummary } from './nagios'
import { writeNagiosConfig, reloadNagios } from './nagiosConfig'

const INTERNAL_SECRET = process.env.GATEWAY_INTERNAL_SECRET || 'dev-secret-change-in-production'

/**
 * Middleware to check internal secret header
 */
function checkInternalSecret(req: Request, res: Response, next: () => void): void {
  const providedSecret = req.headers['x-internal-secret']
  
  if (!providedSecret || providedSecret !== INTERNAL_SECRET) {
    res.status(401).json({
      success: false,
      message: 'Unauthorized',
    })
    return
  }
  
  next()
}

/**
 * Create and configure engine router
 */
export function createEngineRouter(): Router {
  const router = express.Router()
  
  // Apply internal secret middleware to all routes
  router.use(checkInternalSecret)
  
  // Nagios routes
  router.get('/nagios/summary', async (req: Request, res: Response) => {
    try {
      // Note: Summary is workspace-agnostic (total counts)
      // If workspace-specific totals are needed, filter services and calculate
      const summary = await getNagiosSummary()
      res.json({
        success: true,
        data: summary,
      })
    } catch (err) {
      console.error('Error fetching Nagios summary:', err)
      res.status(500).json({
        success: false,
        message: err instanceof Error ? err.message : 'Failed to fetch Nagios summary',
      })
    }
  })
  
  router.get('/nagios/services', async (req: Request, res: Response) => {
    try {
      const raw = req.query.host_prefix
      const hostPrefix: string | undefined =
        typeof raw === 'string' ? raw : Array.isArray(raw) ? (raw[0] ?? undefined) : undefined
      const services = await getNagiosServices(hostPrefix)
      res.json({
        success: true,
        data: services,
      })
    } catch (err) {
      console.error('Error fetching Nagios services:', err)
      res.status(500).json({
        success: false,
        message: err instanceof Error ? err.message : 'Failed to fetch Nagios services',
      })
    }
  })
  
  router.put('/nagios/config', async (req: Request, res: Response) => {
    try {
      const workspaceId = req.headers['x-workspace-id']
      if (!workspaceId || typeof workspaceId !== 'string') {
        return res.status(400).json({
          success: false,
          message: 'Missing or invalid x-workspace-id header',
        })
      }
      
      const workspaceIdNum = parseInt(workspaceId, 10)
      if (isNaN(workspaceIdNum)) {
        return res.status(400).json({
          success: false,
          message: 'Invalid workspace ID format',
        })
      }
      
      const config = req.body.config
      if (!config || typeof config !== 'string') {
        return res.status(400).json({
          success: false,
          message: 'Missing or invalid config in request body',
        })
      }
      
      // Validate config length (safety check)
      if (config.length > 1000000) { // 1MB limit
        return res.status(400).json({
          success: false,
          message: 'Config file too large (max 1MB)',
        })
      }
      
      const { written_path } = await writeNagiosConfig(workspaceIdNum, config)

      res.json({
        success: true,
        message: 'Config written successfully',
        written_path,
      })
    } catch (err) {
      console.error('Error writing Nagios config:', err)
      res.status(500).json({
        success: false,
        message: err instanceof Error ? err.message : 'Failed to write Nagios config',
      })
    }
  })
  
  router.post('/nagios/reload', async (req: Request, res: Response) => {
    try {
      const result = await reloadNagios()
      res.json({
        success: result.success,
        message: result.message,
        validated: result.validated,
        reloaded: result.reloaded,
        reload_skipped: result.reload_skipped,
        method: result.method,
        stdout: result.stdout,
        stderr: result.stderr,
      })
    } catch (err) {
      console.error('Error reloading Nagios:', err)
      res.status(500).json({
        success: false,
        message: err instanceof Error ? err.message : 'Failed to reload Nagios',
        validated: false,
        reloaded: false,
      })
    }
  })
  
  // Debug route (dev only)
  if (process.env.NODE_ENV !== 'production') {
    router.get('/_debug/routes', (req: Request, res: Response) => {
      const routes = [
        'GET /internal/engines/nagios/summary',
        'GET /internal/engines/nagios/services',
        'PUT /internal/engines/nagios/config',
        'POST /internal/engines/nagios/reload',
        'GET /internal/engines/_debug/routes',
      ]
      res.json({
        success: true,
        routes,
      })
    })
  }
  
  // 404 handler for internal routes (must return JSON)
  router.use((req: Request, res: Response) => {
    res.status(404).json({
      success: false,
      message: `Route not found: ${req.method} ${req.path}`,
    })
  })
  
  return router
}

/**
 * Register engine routes (backward compatibility)
 */
export function registerEngineRoutes(app: express.Application): void {
  const engineRouter = createEngineRouter()
  app.use('/internal/engines', engineRouter)
}

import express, { Request, Response, Router } from 'express'

const INTERNAL_SECRET = process.env.GATEWAY_INTERNAL_SECRET || ''

/**
 * Middleware to check internal secret header
 */
function checkInternalSecret(req: Request, res: Response, next: () => void): void {
  if (!INTERNAL_SECRET) {
    res.status(500).json({
      success: false,
      message: 'Gateway internal secret is not configured',
    })
    return
  }
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

  router.get('/native/status', (_req: Request, res: Response) => {
    res.json({
      success: true,
      data: {
        engine: 'native',
        message: 'QynSight native monitoring is owned by the Laravel scheduler command observe:run-checks.',
      },
    })
  })

  router.all('/nagios*', (_req: Request, res: Response) => {
    res.status(410).json({
      success: false,
      code: 'nagios_removed',
      message: 'Nagios has been removed from the runtime path. Use QynSight native monitoring instead.',
    })
  })

  // Debug route (dev only)
  if (process.env.NODE_ENV !== 'production') {
    router.get('/_debug/routes', (req: Request, res: Response) => {
      const routes = [
        'GET /internal/engines/native/status',
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

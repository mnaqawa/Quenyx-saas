import { Request, Response } from 'express'
import { getNagiosServices, getNagiosSummary } from './nagios'

const INTERNAL_SECRET = process.env.GATEWAY_INTERNAL_SECRET || 'dev-secret-change-in-production'

/**
 * Middleware to check internal secret header
 */
function checkInternalSecret(req: Request, res: Response, next: () => void): void {
  const providedSecret = req.headers['x-internal-secret']
  
  if (!providedSecret || providedSecret !== INTERNAL_SECRET) {
    res.status(403).json({
      success: false,
      message: 'Access denied: invalid or missing internal secret',
    })
    return
  }
  
  next()
}

/**
 * Register engine routes
 */
export function registerEngineRoutes(app: any): void {
  // Internal engine routes (require secret header)
  app.get('/internal/engines/nagios/services', checkInternalSecret, async (req: Request, res: Response) => {
    try {
      const services = await getNagiosServices()
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
  
  app.get('/internal/engines/nagios/summary', checkInternalSecret, async (req: Request, res: Response) => {
    try {
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
}

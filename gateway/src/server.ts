import express, { Request, Response } from 'express'
import { enforceEntitlements } from './entitlementGuard'
import { createBackendProxy } from './proxy'
import { clearCache } from './cache'
import { registerEngineRoutes } from './engines'
import { runReadinessChecks } from './health'

const app = express()
const PORT = process.env.GATEWAY_PORT || 4000

// Request logging middleware (before body parsing to avoid consuming stream)
app.use((req: Request, res: Response, next) => {
  const startTime = Date.now()
  
  res.on('finish', () => {
    const duration = Date.now() - startTime
    const status = res.statusCode
    const method = req.method
    const path = req.path
    
    console.log(`${method} ${path} ${status} ${duration}ms`)
  })
  
  next()
})

// Body parsing for internal engine routes (PUT /nagios/config needs JSON body)
app.use('/internal/engines', express.json({ limit: '10mb' }))

// Register internal engine routes (before entitlement enforcement)
// These routes require x-internal-secret header and are not exposed to browser
registerEngineRoutes(app)

// Apply entitlement enforcement before proxying (doesn't need body)
app.use(enforceEntitlements)

// Proxy all /api/* requests to backend (BEFORE any body parsing middleware)
app.use('/api', createBackendProxy())

// Liveness
app.get('/health', (req: Request, res: Response) => {
  res.json({ status: 'ok', service: 'gateway' })
})

// Readiness: can reach Nagios, can write config dir, can read statusjson.cgi
app.get('/ready', async (req: Request, res: Response) => {
  try {
    const result = await runReadinessChecks()
    if (result.ready) {
      res.json({ status: 'ready', checks: result.checks })
    } else {
      res.status(503).json({ status: 'not_ready', checks: result.checks })
    }
  } catch (err) {
    res.status(503).json({
      status: 'not_ready',
      error: err instanceof Error ? err.message : 'Readiness check failed',
      checks: {},
    })
  }
})

// Start server
app.listen(PORT, () => {
  console.log(`Gateway server running on port ${PORT}`)
  console.log(`Backend URL: ${process.env.BACKEND_BASE_URL || 'http://127.0.0.1:8000'}`)
  console.log(`Entitlements cache TTL: ${process.env.ENTITLEMENTS_CACHE_TTL_MS || 30000}ms`)
})

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received, shutting down gracefully')
  clearCache()
  process.exit(0)
})

process.on('SIGINT', () => {
  console.log('SIGINT received, shutting down gracefully')
  clearCache()
  process.exit(0)
})

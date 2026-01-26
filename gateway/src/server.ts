import express, { Request, Response } from 'express'
import { enforceEntitlements } from './entitlementGuard'
import { createBackendProxy } from './proxy'
import { clearCache } from './cache'
import { registerEngineRoutes } from './engines'

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

// Register internal engine routes (before entitlement enforcement)
registerEngineRoutes(app)

// Apply entitlement enforcement before proxying (doesn't need body)
app.use(enforceEntitlements)

// Proxy all /api/* requests to backend (BEFORE any body parsing middleware)
app.use('/api', createBackendProxy())

// Health check endpoint (no body parsing needed for GET)
app.get('/health', (req: Request, res: Response) => {
  res.json({ status: 'ok', service: 'gateway' })
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

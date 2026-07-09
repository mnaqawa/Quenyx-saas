import express from 'express'
import compression from 'compression'
import rateLimit from 'express-rate-limit'
import { config } from './config'
import agentRoutes from './routes/agentRoutes'

const app = express()

app.set('trust proxy', true)
app.use(compression())
app.use(express.json({ limit: '2mb' }))

const limiter = rateLimit({
  windowMs: config.rateLimitWindowMs,
  max: config.rateLimitMax,
  standardHeaders: true,
  legacyHeaders: false,
  message: { success: false, message: 'Rate limit exceeded' },
})

app.get('/health', (_req, res) => {
  res.json({
    success: true,
    service: 'quenyx-agent-gateway',
    version: '1.0.0',
    port: config.port,
  })
})

app.get('/v1/health', (_req, res) => {
  res.json({
    success: true,
    service: 'quenyx-agent-gateway',
    version: '1.0.0',
    default_endpoint: 'https://cloud.quenyx.com:9444',
  })
})

app.use('/v1/agents', limiter, agentRoutes)

// Backward compatible: agents that still target /api/agents/* via QAG
app.use('/api/agents', limiter, agentRoutes)

app.use((_req, res) => {
  res.status(404).json({ success: false, message: 'Not found' })
})

app.listen(config.port, config.host, () => {
  console.log(`Quenyx Agent Gateway (QAG) listening on ${config.host}:${config.port}`)
  console.log(`Forwarding to Laravel at ${config.backendBaseUrl}`)
})

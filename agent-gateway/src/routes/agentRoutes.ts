import { Router, Request, Response } from 'express'
import { forwardToLaravel } from '../proxy/laravel'
import { config } from '../config'

const router = Router()

function agentHeaders(req: Request): Record<string, string> {
  const headers: Record<string, string> = {}
  const secret = req.header('X-Agent-Secret') || req.header('Authorization')?.replace(/^Bearer\s+/i, '')
  if (secret) {
    headers['X-Agent-Secret'] = secret
  }
  const version = req.header('X-Quenyx-Agent-Version')
  if (version) {
    headers['X-Quenyx-Agent-Version'] = version
  }
  const observedIp = req.ip || req.socket.remoteAddress
  if (observedIp) {
    headers['X-Quenyx-Observed-Source-Ip'] = observedIp
  }
  return headers
}

function validateAgentVersion(req: Request, res: Response): boolean {
  if (!config.minAgentVersion) {
    return true
  }
  const version = req.header('X-Quenyx-Agent-Version') || ''
  if (!version.startsWith(config.minAgentVersion)) {
    res.status(426).json({
      success: false,
      message: `Agent version ${version || 'unknown'} is not supported. Minimum: ${config.minAgentVersion}`,
    })
    return false
  }
  return true
}

async function proxyJson(req: Request, res: Response, laravelPath: string): Promise<void> {
  if (!validateAgentVersion(req, res)) {
    return
  }

  const body = req.method === 'GET' || req.method === 'HEAD' ? null : JSON.stringify(req.body ?? {})
  const result = await forwardToLaravel(req.method, laravelPath, agentHeaders(req), body)

  if (result.contentType) {
    res.setHeader('Content-Type', result.contentType)
  }
  res.status(result.status).send(result.body)
}

/** POST /register */
router.post('/register', (req, res) => {
  void proxyJson(req, res, '/api/agents/register')
})

/** POST /:agentId/heartbeat */
router.post('/:agentId/heartbeat', (req, res) => {
  void proxyJson(req, res, `/api/agents/${req.params.agentId}/heartbeat`)
})

/** POST /:agentId/telemetry */
router.post('/:agentId/telemetry', (req, res) => {
  void proxyJson(req, res, `/api/agents/${req.params.agentId}/metrics`)
})

/** POST /:agentId/metrics */
router.post('/:agentId/metrics', (req, res) => {
  void proxyJson(req, res, `/api/agents/${req.params.agentId}/metrics`)
})

/** POST /:agentId/inventory */
router.post('/:agentId/inventory', (req, res) => {
  void proxyJson(req, res, `/api/agents/${req.params.agentId}/inventory`)
})

/** POST /:agentId/evidence */
router.post('/:agentId/evidence', (req, res) => {
  void proxyJson(req, res, `/api/agents/${req.params.agentId}/evidence`)
})

export default router

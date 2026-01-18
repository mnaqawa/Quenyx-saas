import http from 'http'
import { Request, Response } from 'express'
import { createProxyMiddleware } from 'http-proxy-middleware'
import { hashToken } from './cache'

const BACKEND_BASE_URL = process.env.BACKEND_BASE_URL || 'http://127.0.0.1:8000'

// Create keep-alive agent for better connection reuse
const keepAliveAgent = new http.Agent({
  keepAlive: true,
  keepAliveMsecs: 1000,
  maxSockets: 50,
  maxFreeSockets: 10,
})

/**
 * Create proxy middleware for forwarding requests to backend
 */
export function createBackendProxy() {
  return createProxyMiddleware({
    target: BACKEND_BASE_URL,
    changeOrigin: true,
    xfwd: true,
    proxyTimeout: 60000,
    timeout: 60000,
    selfHandleResponse: false,
    followRedirects: false,
    agent: keepAliveAgent,
    pathRewrite: {
      '^/api': '/api', // Keep /api prefix
    },
    onProxyReq: (proxyReq, req: Request) => {
      // Forward critical headers explicitly
      const headersToForward = ['Authorization', 'Cookie', 'Content-Type', 'Accept', 'X-Requested-With']
      
      headersToForward.forEach((headerName) => {
        const value = req.headers[headerName.toLowerCase()]
        if (value) {
          if (typeof value === 'string') {
            proxyReq.setHeader(headerName, value)
          } else if (Array.isArray(value)) {
            proxyReq.setHeader(headerName, value.join(', '))
          }
        }
      })

      // Forward all other headers
      Object.keys(req.headers).forEach((key) => {
        const lowerKey = key.toLowerCase()
        if (!headersToForward.includes(lowerKey)) {
          const value = req.headers[key]
          if (value && typeof value === 'string') {
            proxyReq.setHeader(key, value)
          } else if (Array.isArray(value)) {
            proxyReq.setHeader(key, value.join(', '))
          }
        }
      })

      // Handle body forwarding if it was parsed by middleware
      if (req.body && Object.keys(req.body).length > 0) {
        const contentType = req.headers['content-type'] || ''
        if (contentType.includes('application/json')) {
          const bodyData = JSON.stringify(req.body)
          proxyReq.setHeader('Content-Type', 'application/json')
          proxyReq.setHeader('Content-Length', Buffer.byteLength(bodyData))
          proxyReq.write(bodyData)
        }
      }
    },
    onError: (err, req: Request, res: Response) => {
      const method = req.method || 'UNKNOWN'
      const url = req.url || 'UNKNOWN'
      const target = BACKEND_BASE_URL
      const errorCode = (err as any)?.code || 'UNKNOWN'
      
      // Hash auth header if present for logging
      let authInfo = 'none'
      if (req.headers.authorization) {
        const token = req.headers.authorization.substring(7)
        authInfo = `Bearer ${hashToken(token)}`
      }
      
      console.error(
        `[HPM] Proxy error: ${err.message} | ` +
        `Method: ${method} | ` +
        `URL: ${url} | ` +
        `Target: ${target} | ` +
        `Error Code: ${errorCode} | ` +
        `Auth: ${authInfo}`
      )
      
      if (!res.headersSent) {
        res.status(502).json({
          success: false,
          message: 'Backend service unavailable',
        })
      }
    },
  })
}

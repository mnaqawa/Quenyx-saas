import { Request, Response } from 'express'
import { createProxyMiddleware } from 'http-proxy-middleware'

const BACKEND_BASE_URL = process.env.BACKEND_BASE_URL || 'http://127.0.0.1:8000'

/**
 * Create proxy middleware for forwarding requests to backend
 */
export function createBackendProxy() {
  return createProxyMiddleware({
    target: BACKEND_BASE_URL,
    changeOrigin: true,
    pathRewrite: {
      '^/api': '/api', // Keep /api prefix
    },
    // Forward all headers automatically
    headers: {
      'Connection': 'keep-alive',
    },
    onProxyReq: (proxyReq, req) => {
      // Ensure all original headers are forwarded
      Object.keys(req.headers).forEach((key) => {
        const value = req.headers[key]
        if (value && typeof value === 'string') {
          proxyReq.setHeader(key, value)
        } else if (Array.isArray(value)) {
          proxyReq.setHeader(key, value.join(', '))
        }
      })
    },
    onError: (err, req, res) => {
      console.error('Proxy error:', err.message)
      if (!res.headersSent) {
        (res as Response).status(502).json({
          success: false,
          message: 'Backend service unavailable',
        })
      }
    },
  })
}

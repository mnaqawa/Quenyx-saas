export const config = {
  port: parseInt(process.env.QAG_PORT || '9444', 10),
  host: process.env.QAG_HOST || '0.0.0.0',
  backendBaseUrl: (process.env.BACKEND_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, ''),
  rateLimitWindowMs: parseInt(process.env.QAG_RATE_LIMIT_WINDOW_MS || '60000', 10),
  rateLimitMax: parseInt(process.env.QAG_RATE_LIMIT_MAX || '120', 10),
  minAgentVersion: process.env.QAG_MIN_AGENT_VERSION || '',
}

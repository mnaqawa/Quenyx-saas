import http from 'http'
import https from 'https'

export interface NagiosService {
  host_name: string
  service_name: string
  /** Alias for backend poll; same as service_name (Nagios service_description). */
  service_description?: string
  state: 'ok' | 'warning' | 'critical' | 'unknown' | 'pending'
  last_check_at: string
  next_check_at?: string
  duration_sec: number
  attempt: string
  current_attempt?: number
  max_attempts?: number
  state_type?: string
  output: string
  plugin_output?: string
  long_plugin_output?: string
  perfdata?: string
  check_command?: string
  check_latency_sec?: number
  execution_time_sec?: number
  last_state_change_at?: string
}

/**
 * statusjson.cgi?query=servicelist returns a nested map:
 * data.servicelist = { "<hostname>": { "<service_description>": <state_int> } }
 */
interface NagiosServiceListResponse {
  data: {
    servicelist: Record<string, Record<string, number>>
  }
}

interface NagiosServiceDetailResponse {
  data: {
    service: {
      host_name: string
      service_description: string
      current_state: number
      last_check?: string
      next_check?: string
      last_state_change?: string
      current_attempt?: number
      max_attempts?: number
      plugin_output?: string
      long_plugin_output?: string
      perf_data?: string
      check_command?: string
      check_latency?: string | number
      execution_time?: string | number
      [key: string]: unknown
    }
  }
}

interface NagiosServiceCountResponse {
  data: {
    servicecount: {
      ok: number
      warning: number
      critical: number
      unknown: number
      pending: number
    }
  }
}

const NAGIOS_BASE_URL = process.env.NAGIOS_BASE_URL || 'http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi'
const NAGIOS_USER = process.env.NAGIOS_USER || 'nagiosadmin'
const NAGIOS_PASS = process.env.NAGIOS_PASS || 'nagios'

// In-memory cache for services and summary
interface CacheEntry<T> {
  data: T
  expiresAt: number
}

const servicesCache = new Map<string, CacheEntry<NagiosService[]>>()
const summaryCache = new Map<string, CacheEntry<any>>()
const CACHE_TTL_MS = 30000 // 30 seconds

/**
 * Make authenticated request to Nagios API
 */
function nagiosRequest<T>(path: string): Promise<T> {
  return new Promise((resolve, reject) => {
    // Handle both full URLs and relative paths
    let url: URL
    if (path.startsWith('http://') || path.startsWith('https://')) {
      url = new URL(path)
    } else {
      // path should be relative, append to NAGIOS_BASE_URL
      url = new URL(path, NAGIOS_BASE_URL)
    }
    
    const auth = Buffer.from(`${NAGIOS_USER}:${NAGIOS_PASS}`).toString('base64')
    
    const options = {
      headers: {
        'Authorization': `Basic ${auth}`,
        'Accept': 'application/json',
      },
    }
    
    const client = url.protocol === 'https:' ? https : http
    
    const req = client.get(url.toString(), options, (res) => {
      let data = ''
      
      res.on('data', (chunk) => {
        data += chunk
      })
      
      res.on('end', () => {
        if (res.statusCode && res.statusCode >= 200 && res.statusCode < 300) {
          try {
            const json = JSON.parse(data)
            resolve(json as T)
          } catch (e) {
            reject(new Error(`Failed to parse Nagios response: ${e instanceof Error ? e.message : 'Unknown error'}`))
          }
        } else {
          reject(new Error(`Nagios API error: ${res.statusCode} ${res.statusMessage}`))
        }
      })
    })
    
    req.on('error', (err) => {
      reject(new Error(`Nagios request failed: ${err.message}`))
    })
    
    req.setTimeout(30000, () => {
      req.destroy()
      reject(new Error('Nagios request timeout'))
    })
  })
}

/**
 * Convert Nagios state number to string
 */
function stateToString(state: number): 'ok' | 'warning' | 'critical' | 'unknown' | 'pending' {
  switch (state) {
    case 0:
      return 'ok'
    case 1:
      return 'warning'
    case 2:
      return 'critical'
    case 3:
      return 'unknown'
    default:
      return 'pending'
  }
}

/**
 * Calculate duration in seconds from last_state_change
 */
function calculateDuration(lastStateChange: string): number {
  const changeTime = new Date(lastStateChange).getTime()
  const now = Date.now()
  return Math.floor((now - changeTime) / 1000)
}

/**
 * Normalize Nagios service detail to our structure (full fields for poll/API).
 * Includes service_description so backend can use it if service_name is missing.
 */
function normalizeService(detail: NagiosServiceDetailResponse['data']['service']): NagiosService {
  const cur = detail.current_attempt ?? 0
  const max = detail.max_attempts ?? 0
  const lastCheck = detail.last_check ?? ''
  const lastStateChange = detail.last_state_change ?? ''
  const nextCheck = detail.next_check ?? ''
  const pluginOutput = detail.plugin_output ?? ''
  const longOutput = detail.long_plugin_output ?? ''
  const serviceDesc = detail.service_description ?? ''
  const latency = detail.check_latency != null ? Number(detail.check_latency) : undefined
  const execTime = detail.execution_time != null ? Number(detail.execution_time) : undefined
  return {
    host_name: detail.host_name,
    service_name: serviceDesc,
    service_description: serviceDesc,
    state: stateToString(detail.current_state),
    last_check_at: lastCheck,
    next_check_at: nextCheck || undefined,
    last_state_change_at: lastStateChange || undefined,
    duration_sec: lastStateChange ? calculateDuration(lastStateChange) : 0,
    attempt: `${cur}/${max}`,
    current_attempt: cur,
    max_attempts: max,
    output: longOutput || pluginOutput,
    plugin_output: pluginOutput || undefined,
    long_plugin_output: longOutput || undefined,
    perfdata: detail.perf_data,
    check_command: detail.check_command,
    check_latency_sec: latency,
    execution_time_sec: execTime,
  }
}

/**
 * Fetch service list from Nagios
 */
async function fetchServiceList(hostPrefix?: string): Promise<NagiosServiceListResponse> {
  const url = `${NAGIOS_BASE_URL}/cgi-bin/statusjson.cgi?query=servicelist`
  // Note: Nagios statusjson.cgi doesn't support host_prefix filtering directly
  // We'll filter after fetching
  return nagiosRequest<NagiosServiceListResponse>(url)
}

/**
 * Fetch service details for a specific host/service
 */
async function fetchServiceDetail(hostname: string, serviceDescription: string): Promise<NagiosServiceDetailResponse> {
  const encodedHost = encodeURIComponent(hostname)
  const encodedService = encodeURIComponent(serviceDescription)
  const url = `${NAGIOS_BASE_URL}/cgi-bin/statusjson.cgi?query=service&hostname=${encodedHost}&servicedescription=${encodedService}`
  return nagiosRequest<NagiosServiceDetailResponse>(url)
}

/**
 * Fetch service count summary
 */
async function fetchServiceCount(): Promise<NagiosServiceCountResponse> {
  const url = `${NAGIOS_BASE_URL}/cgi-bin/statusjson.cgi?query=servicecount`
  return nagiosRequest<NagiosServiceCountResponse>(url)
}

/** statusjson.cgi hostlist response: data.hostlist is object keyed by host name */
interface NagiosHostlistResponse {
  data?: {
    hostlist?: Record<string, unknown>
  }
}

/**
 * Proxy statusjson.cgi?query=hostlist so the backend can assert ws{workspaceId}- hosts exist after publish.
 * Returns { hostlist: string[] } of host names.
 */
export async function getNagiosHostlist(): Promise<{ hostlist: string[] }> {
  const base = NAGIOS_BASE_URL.replace(/\?.*$/, '')
  const url = `${base}?query=hostlist`
  const json = await nagiosRequest<NagiosHostlistResponse>(url)
  const raw = json?.data?.hostlist
  const hostlist: string[] = typeof raw === 'object' && raw !== null ? Object.keys(raw) : []
  return { hostlist }
}

/**
 * Flatten statusjson.cgi servicelist nested map into rows.
 * Shape: data.servicelist[hostname][service_description] = state_int
 */
function flattenServicelist(
  servicelist: Record<string, Record<string, number>>,
  hostPrefix?: string
): Array<{ host_name: string; service_description: string; current_state: number }> {
  const rows: Array<{ host_name: string; service_description: string; current_state: number }> = []
  const prefix = hostPrefix != null && typeof hostPrefix === 'string' ? hostPrefix : undefined
  for (const hostname of Object.keys(servicelist)) {
    if (prefix && prefix !== '' && !hostname.startsWith(prefix)) {
      continue
    }
    const hostData = servicelist[hostname]
    if (hostData == null || typeof hostData !== 'object') {
      continue
    }
    for (const serviceDesc of Object.keys(hostData)) {
      const stateInt = hostData[serviceDesc]
      if (typeof stateInt === 'number') {
        rows.push({
          host_name: hostname,
          service_description: serviceDesc,
          current_state: stateInt,
        })
      }
    }
  }
  return rows
}

const CONCURRENCY = 5

/**
 * Run up to CONCURRENCY promises at a time.
 */
async function runWithConcurrency<T, R>(
  items: T[],
  fn: (item: T) => Promise<R>
): Promise<R[]> {
  const results: R[] = []
  let index = 0
  async function worker(): Promise<void> {
    while (index < items.length) {
      const i = index++
      results[i] = await fn(items[i])
    }
  }
  await Promise.all(Array.from({ length: Math.min(CONCURRENCY, items.length) }, () => worker()))
  return results
}

/**
 * Fetch all services: servicelist for state + per-service detail for last_check, plugin_output, attempt, etc.
 */
async function fetchAllServices(hostPrefix?: string): Promise<NagiosService[]> {
  const serviceListResponse = await fetchServiceList(hostPrefix)
  const servicelist = serviceListResponse?.data?.servicelist
  if (!servicelist || typeof servicelist !== 'object') {
    return []
  }

  const rows = flattenServicelist(servicelist, hostPrefix)
  if (rows.length === 0) return []

  const details = await runWithConcurrency(rows, async (r) => {
    try {
      const res = await fetchServiceDetail(r.host_name, r.service_description)
      const svc = res?.data?.service
      if (svc) return normalizeService(svc)
    } catch {
      // Fallback to state-only row if detail fetch fails
    }
    return {
      host_name: r.host_name,
      service_name: r.service_description,
      service_description: r.service_description,
      state: stateToString(r.current_state),
      last_check_at: '',
      next_check_at: undefined,
      duration_sec: 0,
      attempt: '0/0',
      current_attempt: 0,
      max_attempts: 0,
      output: '',
      plugin_output: undefined,
      long_plugin_output: undefined,
      last_state_change_at: undefined,
    } as NagiosService
  })

  return details
}

/**
 * Get cached services or fetch fresh
 * @param hostPrefix Optional host name prefix to filter services (e.g., 'ws84-')
 */
export async function getNagiosServices(hostPrefix?: string | string[]): Promise<NagiosService[]> {
  const prefixStr = hostPrefix == null ? undefined : typeof hostPrefix === 'string' ? hostPrefix : (Array.isArray(hostPrefix) ? hostPrefix[0] : undefined)
  const cacheKey = prefixStr ?? 'all'
  const cached = servicesCache.get(cacheKey)
  const now = Date.now()

  if (cached && cached.expiresAt > now) {
    return cached.data
  }

  const services = await fetchAllServices(prefixStr)
  servicesCache.set(cacheKey, {
    data: services,
    expiresAt: now + CACHE_TTL_MS,
  })
  
  return services
}

/**
 * Get cached summary or fetch fresh
 */
export async function getNagiosSummary(): Promise<{
  totals: {
    ok: number
    warning: number
    critical: number
    unknown: number
    pending: number
  }
  fetched_at: string
}> {
  const cacheKey = 'summary'
  const cached = summaryCache.get(cacheKey)
  const now = Date.now()
  
  if (cached && cached.expiresAt > now) {
    return cached.data
  }
  
  const countResponse = await fetchServiceCount()
  const summary = {
    totals: countResponse.data.servicecount,
    fetched_at: new Date().toISOString(),
  }
  
  summaryCache.set(cacheKey, {
    data: summary,
    expiresAt: now + CACHE_TTL_MS,
  })
  
  return summary
}

/**
 * Check if statusjson.cgi is reachable (for gateway readiness).
 */
export async function canReachStatusjson(): Promise<{ ok: boolean; error?: string }> {
  try {
    await getNagiosSummary()
    return { ok: true }
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : String(e) }
  }
}

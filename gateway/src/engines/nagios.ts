import http from 'http'
import https from 'https'

interface NagiosService {
  host_name: string
  service_name: string
  state: 'ok' | 'warning' | 'critical' | 'unknown' | 'pending'
  last_check_at: string
  duration_sec: number
  attempt: string
  output: string
  perfdata?: string
}

interface NagiosServiceListResponse {
  data: {
    servicelist: {
      [key: string]: {
        host_name: string
        service_description: string
        current_state: number
        last_check: string
        last_state_change: string
        current_attempt: number
        max_attempts: number
        plugin_output: string
        long_plugin_output?: string
        perf_data?: string
      }
    }
  }
}

interface NagiosServiceDetailResponse {
  data: {
    service: {
      host_name: string
      service_description: string
      current_state: number
      last_check: string
      last_state_change: string
      current_attempt: number
      max_attempts: number
      plugin_output: string
      long_plugin_output?: string
      perf_data?: string
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
 * Normalize Nagios service data to our structure
 */
function normalizeService(serviceData: {
  host_name: string
  service_description: string
  current_state: number
  last_check: string
  last_state_change: string
  current_attempt: number
  max_attempts: number
  plugin_output: string
  long_plugin_output?: string
  perf_data?: string
}): NagiosService {
  return {
    host_name: serviceData.host_name,
    service_name: serviceData.service_description,
    state: stateToString(serviceData.current_state),
    last_check_at: serviceData.last_check,
    duration_sec: calculateDuration(serviceData.last_state_change),
    attempt: `${serviceData.current_attempt}/${serviceData.max_attempts}`,
    output: serviceData.long_plugin_output || serviceData.plugin_output,
    perfdata: serviceData.perf_data,
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
 * Fetch all services with details (with concurrency limit and host prefix filtering)
 */
async function fetchAllServices(concurrencyLimit: number = 5, hostPrefix?: string): Promise<NagiosService[]> {
  // Get service list
  const serviceListResponse = await fetchServiceList(hostPrefix)
  const servicelist = serviceListResponse?.data?.servicelist
  if (!servicelist || typeof servicelist !== 'object') {
    return []
  }

  const prefixForFilter: string | undefined = hostPrefix != null && typeof hostPrefix === 'string' ? hostPrefix : undefined

  const serviceKeys = new Set<string>()
  for (const key in servicelist) {
    try {
      const service = servicelist[key]
      if (service == null || typeof service !== 'object') {
        if (process.env.NODE_ENV !== 'test') {
          console.warn('[nagios] Skipping non-object servicelist entry:', { key, type: typeof service })
        }
        continue
      }
      let hostName: string | undefined =
        typeof (service as Record<string, unknown>).host_name === 'string'
          ? (service as Record<string, unknown>).host_name as string
          : undefined
      let serviceDesc: string | undefined =
        typeof (service as Record<string, unknown>).service_description === 'string'
          ? (service as Record<string, unknown>).service_description as string
          : undefined
      if (hostName == null || serviceDesc == null) {
        const sep = String(key).includes(';') ? ';' : '::'
        const parts = String(key).split(sep)
        if (parts.length >= 2) {
          hostName = hostName ?? parts[0]?.trim() ?? ''
          serviceDesc = serviceDesc ?? parts[1]?.trim() ?? ''
        }
      }
      if (hostName == null || serviceDesc == null || String(hostName) === '' || String(serviceDesc) === '') {
        if (process.env.NODE_ENV !== 'test') {
          console.warn('[nagios] Skipping servicelist entry with bad shape:', { key, hostName: hostName ?? 'null', serviceDesc: serviceDesc ?? 'null', keys: Object.keys(service as object).slice(0, 10) })
        }
        continue
      }
      const hostStr = String(hostName)
      if (prefixForFilter != null && prefixForFilter !== '' && typeof hostStr === 'string' && !hostStr.startsWith(prefixForFilter)) {
        continue
      }
      serviceKeys.add(`${hostStr}::${String(serviceDesc)}`)
    } catch (err) {
      const service = servicelist[key]
      if (process.env.NODE_ENV !== 'test') {
        console.warn('[nagios] Skipping servicelist entry after error:', { key, err: err instanceof Error ? err.message : String(err), shape: typeof service === 'object' && service != null ? Object.keys(service as object) : typeof service })
      }
    }
  }
  
  // Fetch details for each service with concurrency limit
  const services: NagiosService[] = []
  const serviceArray = Array.from(serviceKeys)
  
  // Simple concurrency control
  for (let i = 0; i < serviceArray.length; i += concurrencyLimit) {
    const batch = serviceArray.slice(i, i + concurrencyLimit)
    const promises = batch.map(async (key) => {
      const [hostname, serviceDescription] = key.split('::')
      try {
        const detailResponse = await fetchServiceDetail(hostname, serviceDescription)
        return normalizeService(detailResponse.data.service)
      } catch (err) {
        // Fallback to servicelist data if detail fetch fails (guard s.host_name / s.service_description)
        const serviceKey = Object.keys(servicelist).find((k) => {
          const s = servicelist[k]
          const h = s?.host_name
          const d = s?.service_description
          return h != null && d != null && String(h) === hostname && String(d) === serviceDescription
        })
        if (serviceKey) {
          const s = servicelist[serviceKey]
          if (s?.host_name != null && s?.service_description != null) {
            return normalizeService(s)
          }
        }
        throw err
      }
    })
    
    const batchResults = await Promise.all(promises)
    services.push(...batchResults)
  }
  
  return services
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

  const services = await fetchAllServices(10, prefixStr)
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

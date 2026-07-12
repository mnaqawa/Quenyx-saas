import type { ObserveServiceRow } from '../types/observe'

const STATUS_RANK: Record<string, number> = {
  critical: 0,
  unreachable: 1,
  warning: 2,
  unknown: 3,
  pending: 4,
  ok: 5,
}

export function workspaceHostPrefix(workspaceId: number | string): string {
  return `ws${workspaceId}-`
}

export function shortHostName(fullHost: string, workspaceId?: number | string | null): string {
  if (!workspaceId) return fullHost
  const prefix = workspaceHostPrefix(workspaceId)
  return fullHost.startsWith(prefix) ? fullHost.slice(prefix.length) : fullHost
}

export function hostNamesMatch(a: string, b: string, workspaceId?: number | string | null): boolean {
  if (a === b) return true
  if (!workspaceId) return false
  const prefix = workspaceHostPrefix(workspaceId)
  return a === `${prefix}${b}` || b === `${prefix}${a}`
}

/** Operating system from host tags (e.g. os:linux) — returns null when unknown. */
export function operatingSystemFromTags(tags: string[] | undefined | null): string | null {
  if (!tags?.length) return null
  for (const tag of tags) {
    const lower = tag.toLowerCase().trim()
    if (lower.startsWith('os:')) {
      const value = tag.slice(tag.indexOf(':') + 1).trim()
      return value || null
    }
    if (['linux', 'windows', 'macos', 'freebsd', 'unix'].includes(lower)) {
      return tag
    }
  }
  return null
}

export function worstServiceStatus(statuses: string[]): string {
  if (statuses.length === 0) return 'unknown'
  return statuses.reduce((worst, status) =>
    (STATUS_RANK[status] ?? 99) < (STATUS_RANK[worst] ?? 99) ? status : worst,
  )
}

/**
 * Host-level rollup for the Hosts table.
 * Host-Alive drives reachability; incomplete metrics (unknown) must not mark a live host UNKNOWN.
 */
export function hostRollupStatus(rows: ObserveServiceRow[]): string {
  if (rows.length === 0) return 'unknown'

  const isHostAlive = (row: ObserveServiceRow) => {
    const name = (row.service ?? '').toLowerCase()
    return name === 'host-alive' || name === 'host alive' || name.includes('host-alive')
  }

  const hostAlive = rows.find(isHostAlive)
  const hostAliveStatus = (hostAlive?.status ?? '').toLowerCase()

  if (hostAliveStatus === 'critical' || hostAliveStatus === 'unreachable') {
    return 'critical'
  }

  const hasCritical = rows.some((r) => {
    const s = (r.status ?? '').toLowerCase()
    return s === 'critical' || s === 'unreachable'
  })
  if (hasCritical) return 'critical'

  const hasWarning = rows.some((r) => (r.status ?? '').toLowerCase() === 'warning')
  if (hasWarning) return 'warning'

  if (hostAliveStatus === 'ok') return 'ok'

  const hardStatuses = rows
    .map((r) => (r.status ?? '').toLowerCase())
    .filter((s) => s !== 'unknown' && s !== 'pending')
  if (hardStatuses.length > 0) {
    return worstServiceStatus(hardStatuses)
  }

  return worstServiceStatus(rows.map((r) => r.status ?? 'pending'))
}

export interface HostRuntimeSummary {
  status: string
  lastCheck: string
  serviceCheckCount: number
  healthy: number
  warning: number
  critical: number
  unknown: number
  pending: number
}

export function summarizeHostServices(
  rows: ObserveServiceRow[],
  hostName: string,
  workspaceId?: number | string | null,
): HostRuntimeSummary {
  const matched = rows.filter((row) => hostNamesMatch(row.host, hostName, workspaceId))
  let lastCheck = ''
  let healthy = 0
  let warning = 0
  let critical = 0
  let unknown = 0
  let pending = 0

  for (const row of matched) {
    if (row.status === 'ok') healthy += 1
    else if (row.status === 'warning') warning += 1
    else if (row.status === 'critical') critical += 1
    else if (row.status === 'pending') pending += 1
    else unknown += 1
    if (row.lastCheckAt && (!lastCheck || row.lastCheckAt > lastCheck)) {
      lastCheck = row.lastCheckAt
    }
  }

  return {
    status: hostRollupStatus(matched),
    lastCheck,
    serviceCheckCount: matched.length,
    healthy,
    warning,
    critical,
    unknown,
    pending,
  }
}

export function buildHostRuntimeMap(
  rows: ObserveServiceRow[],
  workspaceId?: number | string | null,
): Map<string, HostRuntimeSummary> {
  const byHost = new Map<string, ObserveServiceRow[]>()
  for (const row of rows) {
    const short = shortHostName(row.host, workspaceId)
    const list = byHost.get(short) ?? []
    list.push(row)
    byHost.set(short, list)
  }
  const map = new Map<string, HostRuntimeSummary>()
  for (const [host, hostRows] of byHost) {
    map.set(host, summarizeHostServices(hostRows, hostRows[0]?.host ?? host, workspaceId))
  }
  return map
}

export function classifyHostHealth(status: string): 'healthy' | 'warning' | 'critical' {
  if (status === 'critical' || status === 'unreachable') return 'critical'
  if (status === 'warning' || status === 'unknown') return 'warning'
  return 'healthy'
}

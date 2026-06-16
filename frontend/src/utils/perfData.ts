import type { ObserveServiceRow } from '../types/observe'

export type MetricKind = 'cpu' | 'memory' | 'disk' | 'network'

export interface PerfMetric {
  label: string
  value: number
  uom: string
  max?: number
}

export interface HostMetric {
  percent: number | null
  display: string
  service: string
  status: ObserveServiceRow['status']
}

export const METRIC_KEYWORDS: Record<MetricKind, RegExp> = {
  cpu: /\b(cpu|processor|load)\b/i,
  memory: /\b(mem|memory|ram|swap)\b/i,
  disk: /\b(disk|partition|storage|filesystem|mount|space|volume)\b/i,
  network: /\b(network|traffic|bandwidth|latency|ping|interface|eth\d*|nic)\b/i,
}

// Parse Nagios-style performance data: 'label'=value[UOM];warn;crit;min;max ...
export function parsePerfData(perf?: string): PerfMetric[] {
  if (!perf) return []
  const out: PerfMetric[] = []
  const regex = /('[^']+'|"[^"]+"|[^=\s]+)=([^;\s]+)(?:;[^;\s]*)?(?:;[^;\s]*)?(?:;[^;\s]*)?(?:;([^;\s]*))?/g
  let match: RegExpExecArray | null
  while ((match = regex.exec(perf)) !== null) {
    const label = match[1].replace(/^['"]|['"]$/g, '')
    const valueStr = match[2]
    const numMatch = valueStr.match(/-?\d+(?:\.\d+)?/)
    if (!numMatch) continue
    const value = parseFloat(numMatch[0])
    const uom = valueStr.slice(numMatch[0].length)
    const max = match[3] ? parseFloat(match[3]) : undefined
    out.push({ label, value, uom, max: Number.isFinite(max) ? max : undefined })
  }
  return out
}

const BYTE_UOM = /^(b|kb|mb|gb|tb|kib|mib|gib|tib)$/i

// Derive a percentage (or a raw display) for a metric kind from a single service row.
export function extractMetric(
  row: ObserveServiceRow,
  kind: MetricKind,
): { percent: number | null; display: string } {
  const perf = parsePerfData(row.perfData)
  const info = row.info || row.status_information || row.pluginOutput || ''

  // 1) Prefer a percentage metric in perfdata
  const pctMetric =
    perf.find((p) => p.uom === '%' && METRIC_KEYWORDS[kind].test(p.label)) ?? perf.find((p) => p.uom === '%')
  let percent: number | null = pctMetric ? pctMetric.value : null

  // 2) Byte metric with a max -> used%
  if (percent == null) {
    const byteMetric = perf.find((p) => BYTE_UOM.test(p.uom) && p.max && p.max > 0)
    if (byteMetric && byteMetric.max) percent = (byteMetric.value / byteMetric.max) * 100
  }

  // 3) Fallback: a percentage in the plugin output text
  if (percent == null) {
    const pm = info.match(/(\d+(?:\.\d+)?)\s*%/)
    if (pm) {
      let v = parseFloat(pm[1])
      if (/\b(free|available)\b/i.test(info)) v = 100 - v
      percent = v
    }
  }

  // 4) CPU load average (not a percentage) -> show raw load
  if (percent == null && kind === 'cpu') {
    const lm = info.match(/load average:\s*([\d.]+)/i)
    if (lm) return { percent: null, display: `load ${lm[1]}` }
    const loadPerf = perf.find((p) => /load1?/i.test(p.label))
    if (loadPerf) return { percent: null, display: `load ${loadPerf.value}` }
  }

  if (percent != null && Number.isFinite(percent)) {
    const rounded = Math.round(Math.max(0, Math.min(100, percent)))
    return { percent: rounded, display: `${rounded}%` }
  }
  return { percent: null, display: '' }
}

// Pick the best service for a metric kind among a host's services.
export function pickHostMetric(rows: ObserveServiceRow[], kind: MetricKind): HostMetric | null {
  const candidates = rows.filter((r) => METRIC_KEYWORDS[kind].test(r.service))
  if (candidates.length === 0) return null
  let fallback: HostMetric | null = null
  for (const row of candidates) {
    const { percent, display } = extractMetric(row, kind)
    if (display) {
      return { percent, display, service: row.service, status: row.status }
    }
    if (!fallback) {
      fallback = { percent: null, display: '', service: row.service, status: row.status }
    }
  }
  return fallback
}

const STATUS_RANK: Record<ObserveServiceRow['status'], number> = {
  critical: 0,
  warning: 1,
  unknown: 2,
  pending: 3,
  ok: 4,
}

// Worst (most severe) status among a host's services.
export function worstStatus(rows: ObserveServiceRow[]): ObserveServiceRow['status'] {
  return rows.reduce<ObserveServiceRow['status']>(
    (acc, row) => (STATUS_RANK[row.status] < STATUS_RANK[acc] ? row.status : acc),
    'ok',
  )
}

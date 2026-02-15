import { useState, useEffect, useMemo, useCallback } from 'react'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import {
  realTimeMetricsFixture,
  systemInfoFixture,
  performanceThresholdsFixture,
  performanceMetricsFixture,
  networkTopologyFixture,
  capacityMetricsFixture,
  alertRulesFixture,
  alertSummaryFixture,
  instancesFixture,
  instanceSummaryFixture,
  reportsFixture,
  reportSummaryFixture,
  dataSourcesFixture,
  dataSourceSummaryFixture,
} from '../fixtures/observeFixtures'
import { servicesFixture } from '../fixtures/servicesFixture'
import { observeService } from '../services/observeService'
import type {
  RealTimeMetrics,
  SystemInfo,
  PerformanceMetric,
  NetworkNode,
  CapacityMetric,
  AlertRule,
  AlertSummary,
  Instance,
  InstanceSummary,
  Report,
  ReportSummary,
  DataSource,
  DataSourceSummary,
  ObserveServicesResponse,
} from '../types/observe'

// Data source toggle: use fixtures if VITE_OBSERVE_USE_FIXTURES=true, otherwise use real API
const USE_FIXTURES = import.meta.env.VITE_OBSERVE_USE_FIXTURES === 'true' || false

// Real-time Monitoring hooks
export function useRealTimeMetrics() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [metrics, setMetrics] = useState<RealTimeMetrics | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setMetrics(null)
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setMetrics(realTimeMetricsFixture)
        setLoading(false)
      }, 300)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getRealTimeMetrics(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setMetrics(data) })
      .catch(() => { if (!cancelled) setMetrics(null) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { metrics, loading }
}

export function useSystemInfo() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [info, setInfo] = useState<SystemInfo | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setInfo(null)
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setInfo(systemInfoFixture)
        setLoading(false)
      }, 200)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getSystemInfo(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setInfo(data) })
      .catch(() => { if (!cancelled) setInfo(null) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { info, loading }
}

export function usePerformanceThresholds() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [thresholds, setThresholds] = useState<Array<{ metric: string; warning: string; critical: string }>>([])

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setThresholds([])
      return
    }

    setThresholds(performanceThresholdsFixture)
  }, [selectedWorkspaceId])

  return { thresholds }
}

// Performance Analytics hooks
export function usePerformanceMetrics(timeRange?: string) {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [metrics, setMetrics] = useState<PerformanceMetric[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setMetrics([])
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setMetrics(performanceMetricsFixture)
        setLoading(false)
      }, 300)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getPerformanceMetrics(Number(selectedWorkspaceId), timeRange)
      .then((data) => { if (!cancelled) setMetrics(Array.isArray(data) ? data : []) })
      .catch(() => { if (!cancelled) setMetrics([]) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId, timeRange])

  return { metrics, loading }
}

// Infrastructure Map hooks
export function useNetworkTopology() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [topology, setTopology] = useState<NetworkNode[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setTopology([])
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setTopology(networkTopologyFixture)
        setLoading(false)
      }, 400)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getNetworkTopology(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setTopology(Array.isArray(data) ? data : []) })
      .catch(() => { if (!cancelled) setTopology([]) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { topology, loading }
}

// Capacity Planning hooks
export function useCapacityMetrics(range?: string) {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [metrics, setMetrics] = useState<CapacityMetric[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setMetrics([])
      setLoading(false)
      return
    }

    const timer = setTimeout(() => {
      setMetrics(capacityMetricsFixture)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
  }, [selectedWorkspaceId, range])

  return { metrics, loading }
}

// Alert Management hooks
export function useAlertRules() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [rules, setRules] = useState<AlertRule[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setRules([])
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setRules(alertRulesFixture)
        setLoading(false)
      }, 300)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getAlertRules(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setRules(Array.isArray(data) ? data : []) })
      .catch(() => { if (!cancelled) setRules([]) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { rules, loading }
}

export function useAlertSummary() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [summary, setSummary] = useState<AlertSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setSummary(null)
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setSummary(alertSummaryFixture)
        setLoading(false)
      }, 200)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getAlertSummary(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setSummary(data ?? null) })
      .catch(() => { if (!cancelled) setSummary(null) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { summary, loading }
}

// Instance Management hooks
export function useInstances(status?: string) {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [instances, setInstances] = useState<Instance[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setInstances([])
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        let filtered = instancesFixture
        if (status && status !== 'All Status') {
          filtered = instancesFixture.filter((i) => i.status === status.toLowerCase())
        }
        setInstances(filtered)
        setLoading(false)
      }, 300)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getInstances(Number(selectedWorkspaceId), status && status !== 'All Status' ? status : undefined)
      .then((data) => { if (!cancelled) setInstances(Array.isArray(data) ? data : []) })
      .catch(() => { if (!cancelled) setInstances([]) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId, status])

  return { instances, loading }
}

export function useInstanceSummary() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [summary, setSummary] = useState<InstanceSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setSummary(null)
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setSummary(instanceSummaryFixture)
        setLoading(false)
      }, 200)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getInstanceSummary(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setSummary(data ?? null) })
      .catch(() => { if (!cancelled) setSummary(null) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { summary, loading }
}

// Reports hooks
export function useReports() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [reports, setReports] = useState<Report[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setReports([])
      setLoading(false)
      return
    }

    const timer = setTimeout(() => {
      setReports(reportsFixture)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
  }, [selectedWorkspaceId])

  return { reports, loading }
}

export function useReportSummary() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [summary, setSummary] = useState<ReportSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setSummary(null)
      setLoading(false)
      return
    }

    const timer = setTimeout(() => {
      setSummary(reportSummaryFixture)
      setLoading(false)
    }, 200)

    return () => clearTimeout(timer)
  }, [selectedWorkspaceId])

  return { summary, loading }
}

// Data Sources hooks
export function useDataSources() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [sources, setSources] = useState<DataSource[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setSources([])
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setSources(dataSourcesFixture)
        setLoading(false)
      }, 300)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getDataSources(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setSources(Array.isArray(data) ? data : []) })
      .catch(() => { if (!cancelled) setSources([]) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { sources, loading }
}

export function useDataSourceSummary() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [summary, setSummary] = useState<DataSourceSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!selectedWorkspaceId) {
      setSummary(null)
      setLoading(false)
      return
    }

    if (USE_FIXTURES) {
      const timer = setTimeout(() => {
        setSummary(dataSourceSummaryFixture)
        setLoading(false)
      }, 200)
      return () => clearTimeout(timer)
    }

    let cancelled = false
    observeService
      .getDataSourceSummary(Number(selectedWorkspaceId))
      .then((data) => { if (!cancelled) setSummary(data ?? null) })
      .catch(() => { if (!cancelled) setSummary(null) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [selectedWorkspaceId])

  return { summary, loading }
}

// Observe KPIs for Real-time Monitoring (host/service totals, problems, unreachable, stale, last poll)
// Map: real target hosts with status from services (for Infrastructure Map). Use realDataOnly in Observe module.
export function useObserveMapHosts(workspaceId: string | null, refetchIntervalMs = 0, realDataOnly = false) {
  const { data: servicesData, loading: servicesLoading } = useObserveServices({
    workspaceId,
    limit: 500,
    refetchIntervalMs,
    realDataOnly,
  })
  const [targets, setTargets] = useState<Array<{ name: string; address: string }>>([])
  const [targetsLoading, setTargetsLoading] = useState(true)

  useEffect(() => {
    if (!workspaceId) {
      setTargets([])
      setTargetsLoading(false)
      return
    }
    setTargets([])
    setTargetsLoading(true)
    let cancelled = false
    observeService
      .getTargets(Number(workspaceId))
      .then((list) => {
        if (!cancelled) setTargets(Array.isArray(list) ? list : (list as any)?.targets ?? [])
      })
      .catch(() => {
        if (!cancelled) setTargets([])
      })
      .finally(() => {
        if (!cancelled) setTargetsLoading(false)
      })
    const intervalMs = refetchIntervalMs > 0 ? refetchIntervalMs : 0
    const id = intervalMs ? window.setInterval(() => {
      if (cancelled) return
      observeService.getTargets(Number(workspaceId)).then((list) => {
        if (!cancelled) setTargets(Array.isArray(list) ? list : (list as any)?.targets ?? [])
      }).catch(() => { if (!cancelled) setTargets([]) })
    }, intervalMs) : 0
    return () => {
      cancelled = true
      if (id) window.clearInterval(id)
    }
  }, [workspaceId, refetchIntervalMs])

  const statusOrder = (s: string) =>
    ['critical', 'warning', 'unknown', 'unreachable', 'pending', 'ok'].indexOf(s.toLowerCase())
  const worstStatus = (statuses: string[]) =>
    statuses.reduce((a, b) => (statusOrder(a) <= statusOrder(b) ? a : b), 'pending')

  const hostToStatus = useMemo(() => {
    const map = new Map<string, string>()
    if (!servicesData?.items) return map
    const prefix = workspaceId ? `ws${workspaceId}-` : ''
    for (const item of servicesData.items) {
      const hostName = item.host?.startsWith(prefix) ? item.host.slice(prefix.length) : item.host
      if (!hostName) continue
      const current = map.get(hostName)
      const s = (item as any).status ?? 'pending'
      map.set(hostName, current ? worstStatus([current, s]) : s)
    }
    return map
  }, [servicesData?.items, workspaceId])

  const hosts = useMemo(() => {
    return targets.map((t) => {
      // Exact match first, then case-insensitive (backend/target name may differ in casing)
      const status =
        hostToStatus.get(t.name) ??
        [...hostToStatus.entries()].find(([k]) => k.toLowerCase() === t.name.toLowerCase())?.[1] ??
        'pending'
      return {
        name: t.name,
        address: t.address,
        status,
      }
    })
  }, [targets, hostToStatus])

  return { hosts, loading: targetsLoading || servicesLoading }
}

/** Infrastructure Map: connections + optional integration-sourced topology from Observe API */
export function useObserveConnections(
  workspaceId: string | null,
  options?: { refetchIntervalMs?: number; includeIntegrations?: boolean }
) {
  const refetchIntervalMs = options?.refetchIntervalMs ?? 0
  const includeIntegrations = options?.includeIntegrations ?? true
  const [data, setData] = useState<import('../types/observe').InfrastructureConnectionsResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!workspaceId) {
      setData(null)
      setLoading(false)
      setError(null)
      return
    }
    setData(null)
    setLoading(true)
    setError(null)
    let cancelled = false
    const fetchConnections = () => {
      observeService
        .getInfrastructureConnections(Number(workspaceId), includeIntegrations)
        .then((res) => {
          if (!cancelled) setData(res)
        })
        .catch((err) => {
          if (!cancelled) {
            setError(err instanceof Error ? err.message : 'Failed to load connections')
            setData(null)
          }
        })
        .finally(() => {
          if (!cancelled) setLoading(false)
        })
    }
    setLoading(true)
    setError(null)
    fetchConnections()
    const id = refetchIntervalMs > 0 ? window.setInterval(fetchConnections, refetchIntervalMs) : 0
    return () => {
      cancelled = true
      if (id) window.clearInterval(id)
    }
  }, [workspaceId, includeIntegrations, refetchIntervalMs])

  return { data, loading, error }
}

/** Infrastructure Map: nmap port scan results per host */
export function useObservePortScans(
  workspaceId: string | null,
  options?: { refetchIntervalMs?: number }
) {
  const refetchIntervalMs = options?.refetchIntervalMs ?? 0
  const [data, setData] = useState<import('../types/observe').PortScanResult[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [refreshKey, setRefreshKey] = useState(0)

  const fetchScans = useCallback(() => {
    if (!workspaceId) return
    observeService
      .getPortScans(Number(workspaceId))
      .then((res) => setData(Array.isArray(res) ? res : []))
      .catch((err) => {
        setError(err instanceof Error ? err.message : 'Failed to load port scans')
        setData([])
      })
      .finally(() => setLoading(false))
  }, [workspaceId])

  useEffect(() => {
    if (!workspaceId) {
      setData([])
      setLoading(false)
      setError(null)
      return
    }
    setLoading(true)
    setError(null)
    let cancelled = false
    observeService
      .getPortScans(Number(workspaceId))
      .then((res) => {
        if (!cancelled) setData(Array.isArray(res) ? res : [])
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load port scans')
          setData([])
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    const id = refetchIntervalMs > 0 ? window.setInterval(fetchScans, refetchIntervalMs) : 0
    return () => {
      cancelled = true
      if (id) window.clearInterval(id)
    }
  }, [workspaceId, refetchIntervalMs, refreshKey, fetchScans])

  const refresh = useCallback(() => {
    setRefreshKey((k) => k + 1)
  }, [])

  return { data, loading, error, refresh }
}

export function useObserveKpis(workspaceId: string | null, refreshKey = 0) {
  const { data, loading, error } = useObserveServices({
    workspaceId,
    limit: 1,
    refreshKey,
  })
  const problems =
    data != null
      ? (data.serviceTotals.warning ?? 0) +
        (data.serviceTotals.critical ?? 0) +
        (data.serviceTotals.unknown ?? 0) +
        (data.serviceTotals.unreachable ?? 0)
      : 0
  return {
    hostTotals: data?.hostTotals ?? { up: 0, down: 0, unreachable: 0, pending: 0 },
    serviceTotals: data?.serviceTotals ?? { ok: 0, warning: 0, unknown: 0, critical: 0, pending: 0, unreachable: 0 },
    problems,
    engineUnreachable: data?.engine_unreachable ?? false,
    stale: data?.stale ?? true,
    lastPollAt: data?.last_poll_at ?? null,
    loading,
    error,
  }
}

// Services hooks
interface UseObserveServicesParams {
  workspaceId: string | null
  q?: string
  statuses?: string[]
  limit?: number
  problemsOnly?: boolean
  refreshKey?: number // Optional refresh trigger
  refetchIntervalMs?: number // Optional auto-refresh interval (0 = off)
  /** When true, always use Observe microservice API (no fixtures). Use in Observe module for real data only. */
  realDataOnly?: boolean
}

export function useObserveServices({ workspaceId, q, statuses, limit, problemsOnly, refreshKey = 0, refetchIntervalMs = 0, realDataOnly = false }: UseObserveServicesParams) {
  const [data, setData] = useState<ObserveServicesResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!workspaceId) {
      setData(null)
      setLoading(false)
      setError(null)
      return
    }
    // Clear previous workspace data immediately when switching so UI never shows wrong workspace
    setData(null)
    setLoading(true)
    setError(null)

    const fetchData = async () => {
      setLoading(true)
      setError(null)
      
      try {
        const useFixtures = USE_FIXTURES && !realDataOnly
        if (useFixtures) {
          // Use fixtures with filtering
          await new Promise((resolve) => setTimeout(resolve, 300)) // Simulate delay
          let filtered = { ...servicesFixture }

          // Apply problemsOnly filter
          if (problemsOnly) {
            filtered.items = filtered.items.filter(
              (item) => item.status !== 'ok' && item.status !== 'pending'
            )
          }

          // Apply status filter
          if (statuses && statuses.length > 0) {
            filtered.items = filtered.items.filter((item) => statuses.includes(item.status))
          }

          // Apply search query
          if (q && q.trim()) {
            const query = q.toLowerCase()
            filtered.items = filtered.items.filter(
              (item) =>
                item.host.toLowerCase().includes(query) ||
                item.service.toLowerCase().includes(query) ||
                item.info.toLowerCase().includes(query)
            )
          }

          // Apply limit
          if (limit) {
            filtered.items = filtered.items.slice(0, limit)
          }

          // Recalculate totals based on filtered items
          const hostCounts = new Set(filtered.items.map((item) => item.host))
          filtered.hostTotals = {
            up: hostCounts.size,
            down: 0,
            unreachable: 0,
            pending: 0,
          }

          filtered.serviceTotals = {
            ok: filtered.items.filter((item) => item.status === 'ok').length,
            warning: filtered.items.filter((item) => item.status === 'warning').length,
            unknown: filtered.items.filter((item) => item.status === 'unknown').length,
            critical: filtered.items.filter((item) => item.status === 'critical').length,
            pending: filtered.items.filter((item) => item.status === 'pending').length,
          }

          setData(filtered)
        } else {
          // Use real API via observeService (backend returns { success, data: { items, hostTotals, ... } })
          const response = await observeService.getServices(Number(workspaceId), {
            q,
            status: statuses,
            limit,
            problemsOnly,
          })
          const payload = response && typeof response === 'object' && 'data' in response
            ? (response as { data: ObserveServicesResponse }).data
            : (response as ObserveServicesResponse)
          setData(payload)
        }
      } catch (err) {
        const errorMessage = err instanceof Error ? err.message : 'Failed to load services'
        setError(errorMessage)
        // On error, fall back to empty data
        setData({
          hostTotals: { up: 0, down: 0, unreachable: 0, pending: 0 },
          serviceTotals: { ok: 0, warning: 0, unknown: 0, critical: 0, pending: 0 },
          items: [],
        })
      } finally {
        setLoading(false)
      }
    }

    fetchData()
    const intervalMs = refetchIntervalMs > 0 ? refetchIntervalMs : 0
    const id = intervalMs ? window.setInterval(fetchData, intervalMs) : 0
    return () => {
      if (id) window.clearInterval(id)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [workspaceId, q, statuses?.join(','), limit, problemsOnly, refreshKey, refetchIntervalMs, realDataOnly])

  return { data, loading, error }
}

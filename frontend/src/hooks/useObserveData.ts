import { useState, useEffect, useMemo } from 'react'
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

    // Simulate API call delay
    const timer = setTimeout(() => {
      setMetrics(realTimeMetricsFixture)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setInfo(systemInfoFixture)
      setLoading(false)
    }, 200)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setMetrics(performanceMetricsFixture)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setTopology(networkTopologyFixture)
      setLoading(false)
    }, 400)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setRules(alertRulesFixture)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setSummary(alertSummaryFixture)
      setLoading(false)
    }, 200)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      let filtered = instancesFixture
      if (status && status !== 'All Status') {
        filtered = instancesFixture.filter((i) => i.status === status.toLowerCase())
      }
      setInstances(filtered)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setSummary(instanceSummaryFixture)
      setLoading(false)
    }, 200)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setSources(dataSourcesFixture)
      setLoading(false)
    }, 300)

    return () => clearTimeout(timer)
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

    const timer = setTimeout(() => {
      setSummary(dataSourceSummaryFixture)
      setLoading(false)
    }, 200)

    return () => clearTimeout(timer)
  }, [selectedWorkspaceId])

  return { summary, loading }
}

// Observe KPIs for Real-time Monitoring (host/service totals, problems, unreachable, stale, last poll)
// Map: real target hosts with status from services (for Infrastructure Map)
export function useObserveMapHosts(workspaceId: string | null) {
  const { data: servicesData, loading: servicesLoading } = useObserveServices({ workspaceId, limit: 500 })
  const [targets, setTargets] = useState<Array<{ name: string; address: string }>>([])
  const [targetsLoading, setTargetsLoading] = useState(true)

  useEffect(() => {
    if (!workspaceId) {
      setTargets([])
      setTargetsLoading(false)
      return
    }
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
    return () => { cancelled = true }
  }, [workspaceId])

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
      const current = map.get(hostName)
      const s = (item as any).status ?? 'pending'
      map.set(hostName, current ? worstStatus([current, s]) : s)
    }
    return map
  }, [servicesData?.items, workspaceId])

  const hosts = useMemo(() => {
    return targets.map((t) => ({
      name: t.name,
      address: t.address,
      status: hostToStatus.get(t.name) || 'pending',
    }))
  }, [targets, hostToStatus])

  return { hosts, loading: targetsLoading || servicesLoading }
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
}

export function useObserveServices({ workspaceId, q, statuses, limit, problemsOnly, refreshKey = 0 }: UseObserveServicesParams) {
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

    const fetchData = async () => {
      setLoading(true)
      setError(null)
      
      try {
        if (USE_FIXTURES) {
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [workspaceId, q, statuses?.join(','), limit, problemsOnly, refreshKey])

  return { data, loading, error }
}

import { gatewayClient } from './gatewayClient'
import { getAuthToken } from './apiClient'
import type {
  RealTimeMetrics,
  SystemInfo,
  PerformanceMetric,
  PerformanceHistoryRange,
  PerformanceHistoryResponse,
  CapacityPlanningRange,
  CapacityPlanningResponse,
  CapacityScenarioParams,
  CapacityPlanningExportReport,
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
  ServiceDefinition,
  InfrastructureConnectionsResponse,
  PortScanResult,
} from '../types/observe'

export const observeService = {
  // Real-time Monitoring
  async getRealTimeMetrics(workspaceId: number): Promise<RealTimeMetrics> {
    // Placeholder - will read from fixtures
    // Using gatewayClient for future-proofing (currently routes to direct API)
    return gatewayClient.get<RealTimeMetrics>(
      `workspaces/${workspaceId}/observe/real-time/metrics`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getSystemInfo(workspaceId: number): Promise<SystemInfo> {
    return gatewayClient.get<SystemInfo>(
      `workspaces/${workspaceId}/observe/real-time/system-info`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getPerformanceThresholds(workspaceId: number): Promise<Array<{ metric: string; warning: string; critical: string }>> {
    return gatewayClient.get<Array<{ metric: string; warning: string; critical: string }>>(
      `workspaces/${workspaceId}/observe/real-time/thresholds`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  // Infrastructure Map — real data from Observe microservice; optional merge from Integrations
  async getNetworkTopology(workspaceId: number): Promise<NetworkNode[]> {
    return gatewayClient.get<NetworkNode[]>(
      `workspaces/${workspaceId}/observe/infrastructure/topology`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getInfrastructureConnections(
    workspaceId: number,
    includeIntegrations = true
  ): Promise<InfrastructureConnectionsResponse> {
    const q = includeIntegrations ? '?include_integrations=1' : ''
    return gatewayClient.get<InfrastructureConnectionsResponse>(
      `workspaces/${workspaceId}/observe/infrastructure/connections${q}`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getPortScans(workspaceId: number): Promise<PortScanResult[]> {
    return gatewayClient.get<PortScanResult[]>(
      `workspaces/${workspaceId}/observe/infrastructure/port-scans`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async runPortScans(
    workspaceId: number,
    options: {
      hostIds?: number[]
      ports?: 'top100' | 'all' | 'range'
      portsRange?: string
      protocol?: 'tcp' | 'udp'
    }
  ): Promise<{ scanned: number; total: number; errors: string[] }> {
    const body: Record<string, unknown> = {
      ports: options.ports ?? 'top100',
      protocol: options.protocol ?? 'tcp',
    }
    if (options.hostIds && options.hostIds.length > 0) {
      body.host_ids = options.hostIds
    }
    if (options.ports === 'range' && options.portsRange) {
      body.ports_range = options.portsRange
    }
    const res = await gatewayClient.post<{ scanned: number; total: number; errors: string[] }>(
      `workspaces/${workspaceId}/observe/infrastructure/port-scans/run`,
      body,
      { workspaceId, moduleKey: 'qynsight' }
    )
    const data = res as { scanned?: number; total?: number; errors?: string[] }
    return {
      scanned: data?.scanned ?? 0,
      total: data?.total ?? 0,
      errors: Array.isArray(data?.errors) ? data.errors : [],
    }
  },

  // Performance Analytics
  async getPerformanceMetrics(workspaceId: number, timeRange?: string): Promise<PerformanceMetric[]> {
    const endpoint = timeRange
      ? `workspaces/${workspaceId}/observe/performance/metrics?range=${encodeURIComponent(timeRange)}`
      : `workspaces/${workspaceId}/observe/performance/metrics`
    return gatewayClient.get<PerformanceMetric[]>(endpoint, { workspaceId, moduleKey: 'qynsight' })
  },

  async getPerformanceHistory(
    workspaceId: number,
    range: PerformanceHistoryRange = '24h',
  ): Promise<PerformanceHistoryResponse> {
    return gatewayClient.get<PerformanceHistoryResponse>(
      `workspaces/${workspaceId}/observe/performance/metrics?range=${encodeURIComponent(range)}`,
      { workspaceId, moduleKey: 'qynsight' },
    )
  },

  // Capacity Planning
  async getCapacityPlanning(
    workspaceId: number,
    range: CapacityPlanningRange = '30d',
    scenario?: CapacityScenarioParams,
  ): Promise<CapacityPlanningResponse> {
    const params = new URLSearchParams({ range })
    if (scenario?.scenario_template) params.set('scenario_template', scenario.scenario_template)
    if (scenario?.growth_pct != null) params.set('growth_pct', String(scenario.growth_pct))
    if (scenario?.horizon_days != null) params.set('horizon_days', String(scenario.horizon_days))
    if (scenario?.target_resource) params.set('target_resource', scenario.target_resource)
    if (scenario?.hosts) params.set('hosts', scenario.hosts)
    return gatewayClient.get<CapacityPlanningResponse>(
      `workspaces/${workspaceId}/observe/capacity-planning?${params.toString()}`,
      { workspaceId, moduleKey: 'qynsight' },
    )
  },

  async exportCapacityPlanning(
    workspaceId: number,
    range: CapacityPlanningRange = '30d',
    scenario?: CapacityScenarioParams,
  ): Promise<CapacityPlanningExportReport> {
    const params = new URLSearchParams({ range, format: 'json' })
    if (scenario?.scenario_template) params.set('scenario_template', scenario.scenario_template)
    if (scenario?.growth_pct != null) params.set('growth_pct', String(scenario.growth_pct))
    if (scenario?.horizon_days != null) params.set('horizon_days', String(scenario.horizon_days))
    if (scenario?.target_resource) params.set('target_resource', scenario.target_resource)
    if (scenario?.hosts) params.set('hosts', scenario.hosts)

    const baseUrl = import.meta.env.VITE_API_BASE_URL || ''
    const url = `${baseUrl}/api/workspaces/${workspaceId}/observe/capacity-planning/export?${params.toString()}`
    const token = getAuthToken()
    const response = await fetch(url, {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })

    if (!response.ok) {
      let message = 'Export failed'
      try {
        const body = (await response.json()) as { message?: string }
        if (body.message) message = body.message
      } catch {
        // ignore parse errors
      }
      throw new Error(message)
    }

    return response.json() as Promise<CapacityPlanningExportReport>
  },

  async getCapacityMetrics(workspaceId: number, range?: string): Promise<CapacityMetric[]> {
    const endpoint = range
      ? `workspaces/${workspaceId}/observe/capacity/metrics?range=${encodeURIComponent(range)}`
      : `workspaces/${workspaceId}/observe/capacity/metrics`
    return gatewayClient.get<CapacityMetric[]>(endpoint, { workspaceId, moduleKey: 'qynsight' })
  },

  // Alert Management
  async getAlertRules(workspaceId: number): Promise<AlertRule[]> {
    return gatewayClient.get<AlertRule[]>(
      `workspaces/${workspaceId}/observe/alerts/rules`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getAlertSummary(workspaceId: number): Promise<AlertSummary> {
    return gatewayClient.get<AlertSummary>(
      `workspaces/${workspaceId}/observe/alerts/summary`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  // Instance Management
  async getInstances(workspaceId: number, status?: string): Promise<Instance[]> {
    const endpoint = status
      ? `workspaces/${workspaceId}/observe/instances?status=${encodeURIComponent(status)}`
      : `workspaces/${workspaceId}/observe/instances`
    return gatewayClient.get<Instance[]>(endpoint, { workspaceId })
  },

  // Monitored targets (for Infrastructure Map and others)
  async getTargets(workspaceId: number): Promise<Array<{ name: string; address: string; [k: string]: unknown }>> {
    const res = await gatewayClient.get<Array<{ name: string; address: string }> | { targets?: Array<{ name: string; address: string }> }>(
      `workspaces/${workspaceId}/observe/targets`,
      { workspaceId: String(workspaceId), moduleKey: 'qynsight' }
    )
    return Array.isArray(res) ? res : res?.targets ?? []
  },

  // Observe summary (backend /api/workspaces/:id/observe/summary)
  async getObserveSummary(workspaceId: number): Promise<{
    totals: { ok: number; warning: number; critical: number; unknown: number; pending: number }
    last_poll_at: string | null
  }> {
    return gatewayClient.get<{
      totals: { ok: number; warning: number; critical: number; unknown: number; pending: number }
      last_poll_at: string | null
    }>(`workspaces/${workspaceId}/observe/summary`, { workspaceId, moduleKey: 'qynsight' })
  },

  // Services
  async getServices(
    workspaceId: number,
    params?: {
      q?: string
      status?: string[]
      limit?: number
      problemsOnly?: boolean
    }
  ): Promise<ObserveServicesResponse> {
    const queryParams = new URLSearchParams()
    if (params?.q) queryParams.append('q', params.q)
    if (params?.status) params.status.forEach((s) => queryParams.append('status', s))
    if (params?.limit) queryParams.append('limit', params.limit.toString())
    if (params?.problemsOnly) queryParams.append('problemsOnly', 'true')

    const endpoint = `workspaces/${workspaceId}/observe/services${
      queryParams.toString() ? `?${queryParams.toString()}` : ''
    }`
    return gatewayClient.get<ObserveServicesResponse>(endpoint, { workspaceId, moduleKey: 'qynsight' })
  },

  async getInstanceSummary(workspaceId: number): Promise<InstanceSummary> {
    return gatewayClient.get<InstanceSummary>(
      `workspaces/${workspaceId}/observe/instances/summary`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  // Reports
  async getReports(workspaceId: number): Promise<Report[]> {
    return gatewayClient.get<Report[]>(
      `workspaces/${workspaceId}/observe/reports`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getReportSummary(workspaceId: number): Promise<ReportSummary> {
    return gatewayClient.get<ReportSummary>(
      `workspaces/${workspaceId}/observe/reports/summary`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  // Data Sources
  async getDataSources(workspaceId: number): Promise<DataSource[]> {
    return gatewayClient.get<DataSource[]>(
      `workspaces/${workspaceId}/observe/data-sources`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  async getDataSourceSummary(workspaceId: number): Promise<DataSourceSummary> {
    return gatewayClient.get<DataSourceSummary>(
      `workspaces/${workspaceId}/observe/data-sources/summary`,
      { workspaceId, moduleKey: 'qynsight' }
    )
  },

  // Service definitions (capability-driven UI)
  async getServiceDefinitions(
    workspaceId: number,
    params?: { engine?: string; status?: string }
  ): Promise<ServiceDefinition[]> {
    const queryParams = new URLSearchParams()
    if (params?.engine) queryParams.append('engine', params.engine)
    if (params?.status) queryParams.append('status', params.status)

    const endpoint = `workspaces/${workspaceId}/observe/service-definitions${
      queryParams.toString() ? `?${queryParams.toString()}` : ''
    }`
    return gatewayClient.get<ServiceDefinition[]>(endpoint, { workspaceId, moduleKey: 'qynsight' })
  },
}

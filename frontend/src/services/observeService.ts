import { gatewayClient } from './gatewayClient'
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

export const observeService = {
  // Real-time Monitoring
  async getRealTimeMetrics(workspaceId: number): Promise<RealTimeMetrics> {
    // Placeholder - will read from fixtures
    // Using gatewayClient for future-proofing (currently routes to direct API)
    return gatewayClient.get<RealTimeMetrics>(
      `workspaces/${workspaceId}/observe/real-time/metrics`,
      { workspaceId }
    )
  },

  async getSystemInfo(workspaceId: number): Promise<SystemInfo> {
    return gatewayClient.get<SystemInfo>(
      `workspaces/${workspaceId}/observe/real-time/system-info`,
      { workspaceId }
    )
  },

  async getPerformanceThresholds(workspaceId: number): Promise<Array<{ metric: string; warning: string; critical: string }>> {
    return gatewayClient.get<Array<{ metric: string; warning: string; critical: string }>>(
      `workspaces/${workspaceId}/observe/real-time/thresholds`,
      { workspaceId }
    )
  },

  // Infrastructure Map
  async getNetworkTopology(workspaceId: number): Promise<NetworkNode[]> {
    return gatewayClient.get<NetworkNode[]>(
      `workspaces/${workspaceId}/observe/infrastructure/topology`,
      { workspaceId }
    )
  },

  // Performance Analytics
  async getPerformanceMetrics(workspaceId: number, timeRange?: string): Promise<PerformanceMetric[]> {
    const endpoint = timeRange
      ? `workspaces/${workspaceId}/observe/performance/metrics?range=${encodeURIComponent(timeRange)}`
      : `workspaces/${workspaceId}/observe/performance/metrics`
    return gatewayClient.get<PerformanceMetric[]>(endpoint, { workspaceId })
  },

  // Capacity Planning
  async getCapacityMetrics(workspaceId: number, range?: string): Promise<CapacityMetric[]> {
    const endpoint = range
      ? `workspaces/${workspaceId}/observe/capacity/metrics?range=${encodeURIComponent(range)}`
      : `workspaces/${workspaceId}/observe/capacity/metrics`
    return gatewayClient.get<CapacityMetric[]>(endpoint, { workspaceId })
  },

  // Alert Management
  async getAlertRules(workspaceId: number): Promise<AlertRule[]> {
    return gatewayClient.get<AlertRule[]>(
      `workspaces/${workspaceId}/observe/alerts/rules`,
      { workspaceId }
    )
  },

  async getAlertSummary(workspaceId: number): Promise<AlertSummary> {
    return gatewayClient.get<AlertSummary>(
      `workspaces/${workspaceId}/observe/alerts/summary`,
      { workspaceId }
    )
  },

  // Instance Management
  async getInstances(workspaceId: number, status?: string): Promise<Instance[]> {
    const endpoint = status
      ? `workspaces/${workspaceId}/observe/instances?status=${encodeURIComponent(status)}`
      : `workspaces/${workspaceId}/observe/instances`
    return gatewayClient.get<Instance[]>(endpoint, { workspaceId })
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
    return gatewayClient.get<ObserveServicesResponse>(endpoint, { workspaceId })
  },

  async getInstanceSummary(workspaceId: number): Promise<InstanceSummary> {
    return gatewayClient.get<InstanceSummary>(
      `workspaces/${workspaceId}/observe/instances/summary`,
      { workspaceId }
    )
  },

  // Reports
  async getReports(workspaceId: number): Promise<Report[]> {
    return gatewayClient.get<Report[]>(
      `workspaces/${workspaceId}/observe/reports`,
      { workspaceId }
    )
  },

  async getReportSummary(workspaceId: number): Promise<ReportSummary> {
    return gatewayClient.get<ReportSummary>(
      `workspaces/${workspaceId}/observe/reports/summary`,
      { workspaceId }
    )
  },

  // Data Sources
  async getDataSources(workspaceId: number): Promise<DataSource[]> {
    return gatewayClient.get<DataSource[]>(
      `workspaces/${workspaceId}/observe/data-sources`,
      { workspaceId }
    )
  },

  async getDataSourceSummary(workspaceId: number): Promise<DataSourceSummary> {
    return gatewayClient.get<DataSourceSummary>(
      `workspaces/${workspaceId}/observe/data-sources/summary`,
      { workspaceId }
    )
  },
}

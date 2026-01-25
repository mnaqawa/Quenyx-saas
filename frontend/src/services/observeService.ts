import { apiClient } from './apiClient'
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
} from '../types/observe'

export const observeService = {
  // Real-time Monitoring
  async getRealTimeMetrics(workspaceId: number): Promise<RealTimeMetrics> {
    // Placeholder - will read from fixtures
    return apiClient.get<RealTimeMetrics>(`/api/workspaces/${workspaceId}/observe/real-time/metrics`)
  },

  async getSystemInfo(workspaceId: number): Promise<SystemInfo> {
    return apiClient.get<SystemInfo>(`/api/workspaces/${workspaceId}/observe/real-time/system-info`)
  },

  async getPerformanceThresholds(workspaceId: number): Promise<Array<{ metric: string; warning: string; critical: string }>> {
    return apiClient.get<Array<{ metric: string; warning: string; critical: string }>>(
      `/api/workspaces/${workspaceId}/observe/real-time/thresholds`
    )
  },

  // Infrastructure Map
  async getNetworkTopology(workspaceId: number): Promise<NetworkNode[]> {
    return apiClient.get<NetworkNode[]>(`/api/workspaces/${workspaceId}/observe/infrastructure/topology`)
  },

  // Performance Analytics
  async getPerformanceMetrics(workspaceId: number, timeRange?: string): Promise<PerformanceMetric[]> {
    const url = timeRange
      ? `/api/workspaces/${workspaceId}/observe/performance/metrics?range=${encodeURIComponent(timeRange)}`
      : `/api/workspaces/${workspaceId}/observe/performance/metrics`
    return apiClient.get<PerformanceMetric[]>(url)
  },

  // Capacity Planning
  async getCapacityMetrics(workspaceId: number, range?: string): Promise<CapacityMetric[]> {
    const url = range
      ? `/api/workspaces/${workspaceId}/observe/capacity/metrics?range=${encodeURIComponent(range)}`
      : `/api/workspaces/${workspaceId}/observe/capacity/metrics`
    return apiClient.get<CapacityMetric[]>(url)
  },

  // Alert Management
  async getAlertRules(workspaceId: number): Promise<AlertRule[]> {
    return apiClient.get<AlertRule[]>(`/api/workspaces/${workspaceId}/observe/alerts/rules`)
  },

  async getAlertSummary(workspaceId: number): Promise<AlertSummary> {
    return apiClient.get<AlertSummary>(`/api/workspaces/${workspaceId}/observe/alerts/summary`)
  },

  // Instance Management
  async getInstances(workspaceId: number, status?: string): Promise<Instance[]> {
    const url = status
      ? `/api/workspaces/${workspaceId}/observe/instances?status=${encodeURIComponent(status)}`
      : `/api/workspaces/${workspaceId}/observe/instances`
    return apiClient.get<Instance[]>(url)
  },

  async getInstanceSummary(workspaceId: number): Promise<InstanceSummary> {
    return apiClient.get<InstanceSummary>(`/api/workspaces/${workspaceId}/observe/instances/summary`)
  },

  // Reports
  async getReports(workspaceId: number): Promise<Report[]> {
    return apiClient.get<Report[]>(`/api/workspaces/${workspaceId}/observe/reports`)
  },

  async getReportSummary(workspaceId: number): Promise<ReportSummary> {
    return apiClient.get<ReportSummary>(`/api/workspaces/${workspaceId}/observe/reports/summary`)
  },

  // Data Sources
  async getDataSources(workspaceId: number): Promise<DataSource[]> {
    return apiClient.get<DataSource[]>(`/api/workspaces/${workspaceId}/observe/data-sources`)
  },

  async getDataSourceSummary(workspaceId: number): Promise<DataSourceSummary> {
    return apiClient.get<DataSourceSummary>(`/api/workspaces/${workspaceId}/observe/data-sources/summary`)
  },
}

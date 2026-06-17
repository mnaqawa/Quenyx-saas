// Types for QynSight module

export interface RealTimeMetrics {
  cpu: {
    value: number
    cores: string
    frequency: string
  }
  memory: {
    value: number
    used: string
    total: string
  }
  diskIO: {
    value: number
    type: string
    throughput: string
  }
  network: {
    value: number
    speed: string
    type: string
  }
  temperature: {
    value: number
    source: string
  }
}

export interface SystemInfo {
  hostname: string
  os: string
  kernel: string
  uptime: string
  loadAverage: string
}

export interface PerformanceThreshold {
  metric: string
  warning: string
  critical: string
}

export interface PerformanceMetric {
  title: string
  value: string
  trend?: {
    direction: 'up' | 'down'
    value: string
    label: string
  }
  detail?: string
  percentage?: number
}

export type PerformanceMetricKind = 'cpu' | 'memory' | 'disk' | 'network'
export type PerformanceHistoryRange = '1h' | '6h' | '24h' | '7d' | '30d'

export interface PerformanceTrendPoint {
  time: string
  label: string
  cpu: number | null
  memory: number | null
  disk: number | null
  network: number | null
}

export interface PerformanceHistoryHost {
  name: string
  cpu: number | null
  memory: number | null
  disk: number | null
  network: number | null
  last_seen_at: string | null
}

export interface PerformanceHistoryResponse {
  range: PerformanceHistoryRange
  from: string
  to: string
  bucket_seconds: number
  host_count: number
  latest: Record<PerformanceMetricKind, number | null>
  trends: PerformanceTrendPoint[]
  hosts: PerformanceHistoryHost[]
}

export interface NetworkNode {
  id: string
  name: string
  type: 'datacenter' | 'network' | 'edge'
  location: string
  status: 'healthy' | 'degraded' | 'critical'
  details: {
    servers?: number
    nets?: number
    devices?: number
  }
  connections: Array<{
    target: string
    type: 'active' | 'degraded'
  }>
}

/** Infrastructure Map: node from Observe or Integration */
export interface InfrastructureNode {
  id: string
  name: string
  type: 'host' | 'network'
  address?: string
  status?: string
  layer?: string
  source: 'observe' | 'integration'
  integration?: string
}

/** Infrastructure Map: connection from Observe or Integration */
export interface InfrastructureConnection {
  id?: string
  source: string
  destination: string
  type: string
  status: string
  source_origin?: 'observe' | 'integration'
  integration?: string
}

export interface InfrastructureConnectionsResponse {
  nodes: InfrastructureNode[]
  connections: InfrastructureConnection[]
  service_stats?: Record<string, { total: number; critical: number; warning: number }>
  from_integrations?: string[]
}

/** Nmap port scan result for Infrastructure Map */
export interface PortScanResult {
  host_id: number
  host_name: string
  address: string
  scan: {
    id: number
    status: 'pending' | 'running' | 'completed' | 'failed'
    scanned_at: string | null
    open_ports_count: number | null
    error_message: string | null
  } | null
  ports: Array<{
    port: number
    protocol: string
    state: string
    service: string | null
    version: string | null
  }>
}

export interface CapacityMetric {
  title: string
  value: string
  status: 'critical' | 'warning' | 'healthy'
  description: string
  icon: string
}

export type CapacityStatus = 'critical' | 'warning' | 'healthy' | 'insufficient_data'
export type CapacityPlanningRange = '7d' | '30d' | '90d'
export type CapacityTab = 'overview' | 'resource-analysis' | 'optimization' | 'scenarios' | 'budget'

export type CapacityHealthStatus = 'healthy' | 'watch' | 'risk' | 'critical' | 'no_data'
export type CapacityDataConfidence = 'no_data' | 'low' | 'medium' | 'high'

export interface CapacityHealth {
  health_status: CapacityHealthStatus
  risk_score: number | null
  shortest_runway_days: number | null
  primary_risk: string | null
  recommended_action: string | null
  data_confidence: CapacityDataConfidence
}

export interface CapacityRunwayResource {
  months: number | null
  days: number | null
  status: CapacityStatus
}

export interface CapacityRunway {
  cpu: CapacityRunwayResource
  memory: CapacityRunwayResource
  storage: CapacityRunwayResource
}

export interface CapacityTopRisk {
  host: string
  resource: string
  utilization_pct: number
  trend: 'up' | 'down' | 'flat' | 'unknown'
  runway_days: number | null
  risk_level: 'critical' | 'warning' | 'healthy' | 'insufficient_data'
  last_sample_at: string | null
}

export interface CapacityStructuredAdvisor {
  available: boolean
  findings: string[]
  business_impact: string[]
  recommended_actions: string[]
  confidence: CapacityDataConfidence
  data_used: {
    history_samples?: number
    resources?: Record<string, boolean>
  }
}

export interface CapacityScenarioTemplate {
  id: string
  name: string
  default_growth_pct: number
  default_horizon_days: number
  default_resource: string
}

export interface CapacityForecastRequirements {
  cpu: number | null
  memory: number | null
  storage: number | null
  timeline_days: number | null
}

export interface CapacityBudget {
  forecasted_requirements: CapacityForecastRequirements
  cost_estimate_available: boolean
  billing_integration_status: 'not_connected' | 'connected'
  has_forecast?: boolean
}

export interface CapacityPlanningSummary {
  cpu_runway_months: number | null
  memory_runway_months: number | null
  storage_runway_months: number | null
  cost_optimization_potential: number | null
  capacity_risk_score: number | null
  statuses: {
    cpu: CapacityStatus
    memory: CapacityStatus
    storage: CapacityStatus
    cost: CapacityStatus
    risk: CapacityStatus
  }
  health_status?: CapacityHealthStatus
  shortest_runway_days?: number | null
  primary_risk?: string | null
  recommended_action?: string | null
  data_confidence?: CapacityDataConfidence
}

export interface CapacityForecastPoint {
  time: string
  label: string
  cpu: number | null
  memory: number | null
  storage: number | null
  projected?: boolean
}

export interface CapacityGrowthTrend {
  metric: string
  start_pct: number
  end_pct: number
  change_pct: number
  monthly_growth_pct: number | null
}

export interface CapacityAdvisor {
  summary: string
  bullets: string[]
}

export interface CapacityConsumer {
  host: string
  value_pct: number
  metric: string
}

export interface CapacityDistribution {
  host: string
  environment: string
  cpu_pct: number | null
  memory_pct: number | null
  storage_pct: number | null
}

export interface CapacityInsight {
  id: string
  type?: string
  title?: string
  severity?: 'high' | 'medium' | 'low'
  priority: 'high' | 'medium' | 'low'
  affected_resource: string
  resource?: string
  evidence?: string
  issue: string
  recommendation: string
  recommended_action?: string
  expected_impact: string
  operational_impact?: string
  cost_impact_status?: 'unavailable' | 'available'
  cost_impact_message?: string
  estimated_saving: number | null
  created_at: string
}

export interface CapacityHostScenarioImpact {
  host_name: string
  resource: string
  status: 'calculated' | 'insufficient_data'
  current_utilization: number | null
  current_runway_days: number | null
  projected_utilization: number | null
  projected_runway_days: number | null
  risk_before: string
  risk_after: string
  impact_summary: string
}

export interface CapacityScenario {
  id: string
  name: string
  description: string
  limiting_resource: string
  runway_months: number | null
  template?: string
  growth_pct?: number
  horizon_days?: number
  target_resource?: string
  selected_hosts?: string[]
  confidence?: CapacityDataConfidence
  current_runway_days?: number | null
  current_runway_months?: number | null
  projected_runway_days?: number | null
  projected_runway_months?: number | null
  risk_change?: string
  impact_summary?: string
  calculable?: boolean
  host_impacts?: CapacityHostScenarioImpact[]
}

export interface CapacityDiagnostics {
  metrics_history_available: boolean
  total_samples: number
  hosts_with_metrics: number
  oldest_sample_at: string | null
  newest_sample_at: string | null
  supported_metrics: string[]
  insufficient_data_reasons: string[]
}

export interface CapacityPlanningExportReport {
  report_metadata: Record<string, unknown>
  executive_summary: Record<string, unknown>
  capacity_health: CapacityHealth | null
  top_capacity_risks: CapacityTopRisk[]
  runway_summary: CapacityRunway | null
  optimization_insights: CapacityInsight[]
  scenario_results: CapacityScenario[]
  budget_forecast: CapacityBudget | null
  diagnostics: CapacityDiagnostics
  generated_at: string
  workspace_id: number
}

export interface CapacityBudgetPlanning {
  current_monthly_cost: number | null
  forecasted_cost: Array<{ time: string; amount: number | null }>
  budget_variance: number | null
  saving_opportunities: Array<{ title: string; amount: number | null }>
  provider_breakdown: Array<{ provider: string; amount: number | null }>
  forecasted_requirements?: CapacityForecastRequirements
  cost_estimate_available?: boolean
  billing_integration_status?: 'not_connected' | 'connected'
}

export interface CapacityScenarioParams {
  scenario_template?: string
  growth_pct?: number
  horizon_days?: number
  target_resource?: string
  hosts?: string
}

export interface CapacityPlanningResponse {
  health?: CapacityHealth
  runway?: CapacityRunway
  forecast?: CapacityForecastPoint[]
  top_risks?: CapacityTopRisk[]
  resource_consumers?: {
    top_cpu_consumers: CapacityConsumer[]
    top_memory_consumers: CapacityConsumer[]
    top_storage_consumers: CapacityConsumer[]
    distribution: CapacityDistribution[]
  }
  budget?: CapacityBudget
  advisor?: CapacityStructuredAdvisor
  scenarios?: {
    templates: CapacityScenarioTemplate[]
    calculated: CapacityScenario[]
    available_hosts?: string[]
  }
  diagnostics?: CapacityDiagnostics
  summary: CapacityPlanningSummary
  overview: {
    forecast: CapacityForecastPoint[]
    growth_trends: CapacityGrowthTrend[]
    advisor: CapacityAdvisor | null
  }
  resource_analysis: {
    top_cpu_consumers: CapacityConsumer[]
    top_memory_consumers: CapacityConsumer[]
    top_storage_consumers: CapacityConsumer[]
    distribution: CapacityDistribution[]
    top_risks?: CapacityTopRisk[]
  }
  optimization_insights: CapacityInsight[]
  scenario_planning: CapacityScenario[]
  budget_planning: CapacityBudgetPlanning
  meta: {
    data_available: boolean
    last_updated: string | null
    range?: string
    history_points?: number
  }
}

export interface AlertRule {
  id: string
  name: string
  condition: string
  enabled: boolean
  severity: 'critical' | 'warning'
  notificationChannels: string[]
  lastTriggered: string
  triggerCount7d: number
}

export interface AlertSummary {
  activeAlerts: {
    total: number
    critical: number
    warning: number
  }
  criticalAlerts: number
  acknowledgedAlerts: number
  resolvedToday: number
  alertRules: {
    total: number
    enabled: number
  }
  avgResponseTime: string
  notificationChannels: {
    active: number
    total: number
  }
}

export interface AlertHistoryEvent {
  id: string
  rule_id: string | null
  rule_name?: string | null
  severity: string
  title: string
  message: string | null
  status: string
  host_name?: string | null
  service_name?: string | null
  triggered_at: string
  opened_at?: string | null
  acknowledged_at?: string | null
  resolved_at: string | null
  last_seen_at?: string | null
  occurrence_count?: number
  metadata?: Record<string, unknown> | null
}

export interface AlertHistoryFilters {
  status?: string
  severity?: string
  target?: string
  rule?: string
  date_from?: string
  date_to?: string
  limit?: number
}

export interface NotificationChannel {
  id: string
  type: string
  name: string
  configured: boolean
}

export interface CreateAlertRulePayload {
  name: string
  severity: 'critical' | 'warning'
  target_scope: 'all' | 'selected_target' | 'selected_service'
  target_host_id?: number | null
  target_service_key?: string | null
  metric_condition: string
  operator: string
  threshold_value: number
  duration_seconds?: number
  notification_channel?: string | null
  enabled?: boolean
}

export interface MonitoringProfileCheck {
  id: number
  service_key: string
  service_name: string
  check_args: Record<string, unknown>
  enabled: boolean
  sort_order: number
}

export interface MonitoringProfileResponse {
  profile: Record<string, unknown>
  checks: MonitoringProfileCheck[]
}

export interface MonitoringProfileCheckUpdate {
  service_key: string
  check_args?: Record<string, unknown>
  enabled?: boolean
}

export interface Instance {
  id: string
  name: string
  type: string
  ip: string
  os: string
  specs: {
    cores: number
    ram: string
    disk: string
  }
  usage: {
    cpu: number
    memory: number
    disk: number
  }
  uptime: string
  datacenter: string
  status: 'running' | 'stopped' | 'warning'
}

export interface InstanceSummary {
  total: number
  running: number
  warning: number
  avgCpuUsage: number
}

export interface Report {
  id: string
  name: string
  category: string
  date: string
  size: string
  status: 'completed' | 'processing' | 'failed'
}

export interface ReportSummary {
  total: number
  downloads: number
  scheduled: number
  avgSize: string
  backend_available?: boolean
}

export interface DataSource {
  id: string
  name: string
  type: string
  recordCount: number
  lastSync: string
  status: 'connected' | 'error' | 'syncing'
}

export interface DataSourceSummary {
  connected: number
  totalRecords: string
  syncStatus: number
  lastUpdate: string
}

// Services page types (native QynSight check fields)
export interface ObserveServiceRow {
  host: string
  service: string
  status: 'ok' | 'warning' | 'critical' | 'unknown' | 'pending'
  lastCheckAt: string
  nextCheckAt?: string
  durationSec: number
  attempt: string
  currentAttempt?: number
  maxAttempts?: number
  stateType?: string
  info: string
  status_information?: string
  pluginOutput?: string
  longPluginOutput?: string
  perfData?: string
  checkCommand?: string
  checkLatencySec?: number
  executionTimeSec?: number
  lastStateChangeAt?: string
}

export interface ObserveServicesResponse {
  hostTotals: {
    up: number
    down: number
    unreachable: number
    pending: number
  }
  serviceTotals: {
    ok: number
    warning: number
    unknown: number
    critical: number
    pending: number
    unreachable?: number
  }
  items: ObserveServiceRow[]
  meta?: {
    last_poll_at: string
    source_version?: string
  }
  last_poll_at?: string | null
  source_timestamp?: string | null
  engine_unreachable?: boolean
  engine_unreachable_reason?: string | null
  stale?: boolean
}

// Normalized data model contract (Option A - Normalized state)
// This schema will be used when backend is ready, ensuring frontend types don't churn

export interface Host {
  name: string
  address: string
  state: 'up' | 'down' | 'unreachable' | 'pending'
  last_check: string
  duration: number // seconds
  output?: string
  perfdata?: string
}

export interface Service {
  host_name: string
  service_name: string
  state: 'ok' | 'warning' | 'critical' | 'unknown' | 'pending'
  last_check: string
  duration: number // seconds
  attempt: string // e.g., "1/3"
  output?: string
  info?: string // Alias for output for compatibility
  perfdata?: string
}

export interface Event {
  id: string
  host_name: string
  service_name?: string
  state: string
  timestamp: string
  message: string
  type: 'state_change' | 'alert' | 'downtime' | 'acknowledgment'
}

export interface ObserveMeta {
  last_poll_at: string
  source_version?: string
  total_hosts?: number
  total_services?: number
}

// Service definitions (capability-driven UI); no engine syntax
export type ArgsSchemaType = 'string' | 'int' | 'float' | 'bool' | 'json'

export interface ArgsSchemaEntry {
  position: number
  key: string
  default: unknown
  required: boolean
  type?: ArgsSchemaType
  help?: string
}

export interface ServiceDefinition {
  engine: string
  service_key: string
  display_name: string
  /** Short description of what this plugin does and how it helps monitor services. */
  description?: string | null
  check_command: string
  args_schema: ArgsSchemaEntry[]
  capability_flags: string[]
  status: string
}

export const CAPABILITY_SECTIONS: Record<string, string> = {
  supports_thresholds: 'Thresholds',
  supports_ports: 'Port',
  supports_urls: 'URL',
  supports_auth: 'Auth',
  supports_payload: 'Payload',
  supports_intervals: 'Intervals',
  supports_retries: 'Retries',
}

// Types for ShieldObserve module

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

export interface CapacityMetric {
  title: string
  value: string
  status: 'critical' | 'warning' | 'healthy'
  description: string
  icon: string
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

// Services page types (Nagios-derived fields)
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

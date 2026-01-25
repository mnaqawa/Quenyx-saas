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

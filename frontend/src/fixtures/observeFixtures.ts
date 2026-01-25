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

export const realTimeMetricsFixture: RealTimeMetrics = {
  cpu: {
    value: 57,
    cores: '8 cores',
    frequency: '3.2 GHz',
  },
  memory: {
    value: 78,
    used: '12.5 GB',
    total: '16 GB',
  },
  diskIO: {
    value: 22,
    type: 'SSD',
    throughput: '2.4 GB/s',
  },
  network: {
    value: 30,
    speed: '1 Gbps',
    type: 'Ethernet',
  },
  temperature: {
    value: 57,
    source: 'CPU Package',
  },
}

export const systemInfoFixture: SystemInfo = {
  hostname: 'server-01.qyn.local',
  os: 'Ubuntu 22.04.3 LTS',
  kernel: '5.15.0-91-generic',
  uptime: '15d 4h 23m',
  loadAverage: '1.23, 1.45, 1.67',
}

export const performanceThresholdsFixture = [
  { metric: 'CPU', warning: '70%', critical: '90%' },
  { metric: 'Memory', warning: '80%', critical: '95%' },
  { metric: 'Temp', warning: '65°C', critical: '80°C' },
]

export const performanceMetricsFixture: PerformanceMetric[] = [
  {
    title: 'CPU Performance',
    value: '73.2%',
    trend: { direction: 'up', value: '+4.7%', label: 'from last hour' },
    percentage: 73.2,
  },
  {
    title: 'Memory Usage',
    value: '16.0 GB',
    detail: '85.3% of 18.8 GB',
    percentage: 85.3,
  },
  {
    title: 'Disk I/O',
    value: '2.4 GB/s',
    detail: 'Peak throughput',
    percentage: 65,
  },
  {
    title: 'Network I/O',
    value: '847 Mbps',
    trend: { direction: 'up', value: '+17%', label: 'from avg' },
    percentage: 70,
  },
]

export const networkTopologyFixture: NetworkNode[] = [
  {
    id: 'dc1',
    name: 'Primary Datacenter',
    type: 'datacenter',
    location: 'US-East-1',
    status: 'healthy',
    details: { servers: 45, nets: 8 },
    connections: [{ target: 'lan1', type: 'active' }],
  },
  {
    id: 'dc2',
    name: 'Secondary Datacenter',
    type: 'datacenter',
    location: 'US-West-2',
    status: 'healthy',
    details: { servers: 32, nets: 6 },
    connections: [{ target: 'edge1', type: 'degraded' }],
  },
  {
    id: 'lan1',
    name: 'Internal LAN',
    type: 'network',
    location: '10.0.0.0/16',
    status: 'healthy',
    details: { devices: 156 },
    connections: [{ target: 'dc1', type: 'active' }, { target: 'dbnet', type: 'active' }],
  },
  {
    id: 'dbnet',
    name: 'Database Network',
    type: 'network',
    location: '172.16.0.0/24',
    status: 'critical',
    details: { devices: 8 },
    connections: [{ target: 'lan1', type: 'active' }],
  },
  {
    id: 'edge1',
    name: 'Edge Location',
    type: 'edge',
    location: 'EU-Central-1',
    status: 'degraded',
    details: { servers: 12 },
    connections: [{ target: 'dc2', type: 'degraded' }],
  },
]

export const capacityMetricsFixture: CapacityMetric[] = [
  {
    title: 'Time to CPU Limit',
    value: '4.2 months',
    status: 'critical',
    description: 'Critical threshold approaching',
    icon: 'cpu',
  },
  {
    title: 'Memory Runway',
    value: '6.8 months',
    status: 'warning',
    description: 'Planning required',
    icon: 'memory',
  },
  {
    title: 'Storage Runway',
    value: '14.3 months',
    status: 'healthy',
    description: 'Sufficient capacity',
    icon: 'storage',
  },
  {
    title: 'Projected Savings',
    value: '$65,000',
    status: 'healthy',
    description: 'Annual optimization potential',
    icon: 'savings',
  },
]

export const alertRulesFixture: AlertRule[] = [
  {
    id: '1',
    name: 'High CPU Usage',
    condition: 'CPU > 85% for 5 minutes',
    enabled: true,
    severity: 'critical',
    notificationChannels: ['email', 'slack'],
    lastTriggered: '2 hours ago',
    triggerCount7d: 3,
  },
  {
    id: '2',
    name: 'Memory Warning',
    condition: 'Memory > 90% for 10 minutes',
    enabled: true,
    severity: 'warning',
    notificationChannels: ['email'],
    lastTriggered: '1 day ago',
    triggerCount7d: 1,
  },
  {
    id: '3',
    name: 'Disk Space Critical',
    condition: 'Disk > 95% for 1 minute',
    enabled: true,
    severity: 'critical',
    notificationChannels: ['email', 'slack', 'sms'],
    lastTriggered: 'Never',
    triggerCount7d: 0,
  },
  {
    id: '4',
    name: 'Network Latency',
    condition: 'Network > 500ms for 3 minutes',
    enabled: false,
    severity: 'warning',
    notificationChannels: ['email', 'webhook'],
    lastTriggered: '3 days ago',
    triggerCount7d: 8,
  },
]

export const alertSummaryFixture: AlertSummary = {
  activeAlerts: {
    total: 3,
    critical: 2,
    warning: 1,
  },
  alertRules: {
    total: 4,
    enabled: 3,
  },
  avgResponseTime: '4.2 min',
  notificationChannels: {
    active: 3,
    total: 4,
  },
}

export const instancesFixture: Instance[] = [
  {
    id: '1',
    name: 'WEB-SERVER-01',
    type: 'Web Server',
    ip: '192.168.1.10',
    os: 'Ubuntu 22.04',
    specs: { cores: 4, ram: '8GB', disk: '100GB SSD' },
    usage: { cpu: 67, memory: 45, disk: 23 },
    uptime: '15d 4h 23m',
    datacenter: 'Datacenter A',
    status: 'running',
  },
  {
    id: '2',
    name: 'DB-CLUSTER-02',
    type: 'Database',
    ip: '192.168.1.20',
    os: 'CentOS 8',
    specs: { cores: 8, ram: '32GB', disk: '500GB SSD' },
    usage: { cpu: 82, memory: 91, disk: 45 },
    uptime: '23d 12h 45m',
    datacenter: 'Datacenter A',
    status: 'running',
  },
  {
    id: '3',
    name: 'APP-SERVER-03',
    type: 'Application',
    ip: '192.168.1.30',
    os: 'Ubuntu 20.04',
    specs: { cores: 6, ram: '16GB', disk: '200GB SSD' },
    usage: { cpu: 95, memory: 98, disk: 87 },
    uptime: '2d 6h 12m',
    datacenter: 'Datacenter B',
    status: 'warning',
  },
  {
    id: '4',
    name: 'CACHE-SERVER-01',
    type: 'Cache',
    ip: '192.168.1.25',
    os: 'Redis OS',
    specs: { cores: 2, ram: '4GB', disk: '50GB SSD' },
    usage: { cpu: 45, memory: 60, disk: 30 },
    uptime: '8d 2h 15m',
    datacenter: 'Datacenter A',
    status: 'running',
  },
]

export const instanceSummaryFixture: InstanceSummary = {
  total: 6,
  running: 4,
  warning: 1,
  avgCpuUsage: 50,
}

export const reportsFixture: Report[] = [
  {
    id: '1',
    name: 'Monthly Analytics Report',
    category: 'Analytics',
    date: '2024-01-15',
    size: '2.4 MB',
    status: 'completed',
  },
  {
    id: '2',
    name: 'User Engagement Summary',
    category: 'Engagement',
    date: '2024-01-14',
    size: '1.8 MB',
    status: 'processing',
  },
  {
    id: '3',
    name: 'Performance Metrics',
    category: 'Performance',
    date: '2024-01-13',
    size: '3.2 MB',
    status: 'completed',
  },
  {
    id: '4',
    name: 'Traffic Analysis',
    category: 'Traffic',
    date: '2024-01-12',
    size: '1.5 MB',
    status: 'completed',
  },
]

export const reportSummaryFixture: ReportSummary = {
  total: 127,
  downloads: 1203,
  scheduled: 8,
  avgSize: '2.1 MB',
}

export const dataSourcesFixture: DataSource[] = [
  {
    id: '1',
    name: 'PostgreSQL Primary',
    type: 'Database',
    recordCount: 2400000,
    lastSync: '2 minutes ago',
    status: 'connected',
  },
  {
    id: '2',
    name: 'Google Analytics',
    type: 'Analytics',
    recordCount: 150000,
    lastSync: '5 minutes ago',
    status: 'connected',
  },
  {
    id: '3',
    name: 'Stripe Payments',
    type: 'API',
    recordCount: 45000,
    lastSync: '1 hour ago',
    status: 'connected',
  },
  {
    id: '4',
    name: 'MongoDB Logs',
    type: 'Database',
    recordCount: 890000,
    lastSync: '2 hours ago',
    status: 'error',
  },
  {
    id: '5',
    name: 'HubSpot CRM',
    type: 'CRM',
    recordCount: 25000,
    lastSync: 'syncing...',
    status: 'syncing',
  },
]

export const dataSourceSummaryFixture: DataSourceSummary = {
  connected: 12,
  totalRecords: '3.5M',
  syncStatus: 92,
  lastUpdate: '2m',
}

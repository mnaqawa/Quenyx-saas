import type { AiNarrative } from './automation'

/** Sprint 25 — QynVA Enterprise AI Operator + Enterprise Intelligence types. */

export interface OperatorAction {
  module: string
  key: string
  capability: string
  target: string
  label: string
  method: string
  endpoint: string
}

export interface OperatorModule {
  module: string
  name: string
  category: string
  capabilities: string[]
}

export interface OperatorCapabilities {
  module_count: number
  modules: OperatorModule[]
  capabilities: string[]
  actions: OperatorAction[]
  generated_at: string
}

export interface OperatorResult {
  conversation_uuid: string
  message_uuid: string
  answer: AiNarrative
  context_summary: Record<string, number>
  available_actions: OperatorAction[]
  note: string
}

export interface HealthBlock {
  available?: boolean
  reason?: string
  score?: number
  status?: string
  [key: string]: unknown
}

export interface ExecutiveDashboard {
  generated_at: string
  operational_health: HealthBlock
  infrastructure_health: HealthBlock
  compliance_health: HealthBlock
  capacity_forecast: HealthBlock
  top_risks: Array<{ type: string; severity: string; title: string; ref?: string; status?: string }>
  top_recommendations: Array<{ key: string; severity: string; evidence: string; recommendation: string }>
  automation_success: HealthBlock
  ai_usage: HealthBlock
  incident_kpis: Record<string, unknown>
  knowledge_kpis: HealthBlock
  cost_kpis: Record<string, unknown>
}

export interface MetricBlock {
  available: boolean
  reason?: string
  sample_size?: number
  avg_seconds?: number
  human?: string
  [key: string]: unknown
}

export interface EnterpriseAnalytics {
  window_days: number
  generated_at: string
  mttd: MetricBlock
  mttr: { incident: MetricBlock; alert: MetricBlock }
  incident_trends: HealthBlock
  automation_effectiveness: HealthBlock
  ai_adoption: HealthBlock
  knowledge_usage: HealthBlock
  asset_growth: HealthBlock
  capacity_trends: HealthBlock
  notification_statistics: HealthBlock
  executive_kpis: Record<string, number>
}

export interface HealthArea {
  status: string
  [key: string]: unknown
}

export interface PlatformHealthSnapshot {
  generated_at: string
  overall_status: string
  areas: Record<string, HealthArea>
}

export interface EventBusIntrospection {
  bus: {
    event_count: number
    subscriber_count: number
    subscribers: Array<{ key: string; subscribed_to: string[] }>
    events: Record<string, string[]>
  }
  recent: Array<{ uuid: string; name: string; workspace_uuid: string; occurred_at: string; correlation_id?: string | null }>
}

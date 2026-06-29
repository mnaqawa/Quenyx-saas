// Sprint 21 — QynSight Operations Intelligence types.
// All resource identifiers are UUIDs (never numeric). Field names mirror the Laravel backend
// (snake_case). The backend strips the {success,data} envelope via apiClient, so these describe the
// inner `data` shape.

export interface OpsAiNarrative {
  available: boolean
  ai_enabled: boolean
  provider?: string
  model?: string | null
  mocked?: boolean
  content?: string
  structured?: Record<string, unknown> | null
  usage?: { prompt_tokens: number; completion_tokens: number; total_tokens: number }
  generated_at?: string
  error?: string
  error_code?: string
}

export interface OpsServiceStateCounts {
  ok: number
  warning: number
  critical: number
  unknown: number
  pending: number
  unreachable: number
}

export interface OpsUnhealthyHost {
  uuid: string
  name: string
  address: string
  worst_state: string
}

export interface OpsInfrastructureHealth {
  hosts_total: number
  hosts_enabled: number
  services_total: number
  service_state_counts: OpsServiceStateCounts
  unhealthy_hosts: OpsUnhealthyHost[]
  unhealthy_host_count: number
}

export interface OpsAlertSummary {
  uuid: string
  title: string
  severity: string
  status: string
  host: string | null
  service: string | null
  message: string | null
  occurrence_count: number
  triggered_at: string | null
  acknowledged_at: string | null
  resolved_at: string | null
  last_seen_at: string | null
}

export interface OpsCriticalService {
  uuid: string
  name: string
  host: string
  state: string
  since: string | null
}

export interface OpsRisk {
  kind?: string
  severity: string
  summary: string
}

export interface OpsCapacityRisk {
  resource: string
  days: number | null
  months: number | null
  status: string
}

export interface OpsRecommendation {
  type: string
  severity: string
  title: string
  target: string
  rationale: string
  evidence: Array<Record<string, unknown>>
}

export interface OpsInvestigationLogItem {
  action: string
  context_type: string | null
  at: string | null
}

export interface OperationsOverview {
  infrastructure_health: OpsInfrastructureHealth
  open_alerts: OpsAlertSummary[]
  open_alert_count: number
  critical_services: OpsCriticalService[]
  top_operational_risks: OpsRisk[]
  predicted_capacity_risks: OpsCapacityRisk[]
  recent_recommendations: OpsRecommendation[]
  recent_ai_investigations: OpsInvestigationLogItem[]
  generated_at: string
}

export interface OperationsCopilotResponse {
  conversation_uuid: string
  message_uuid: string
  answer: OpsAiNarrative
  evidence: Record<string, unknown>
}

export interface OpsRootCauseLayer {
  layer: string
  observed_value: number | null
  pressure: number
  state: string
  related_alerts?: number
}

export interface OpsRootCause {
  layer: string
  state: string
  observed_value: number | null
  summary: string
}

export interface OpsAlertExplanation {
  operational_impact: { severity: string; status: string; degraded_layers: number; summary: string }
  most_likely_causes: OpsRootCauseLayer[]
  evidence_used: Array<Record<string, unknown>>
  related_alerts: OpsAlertSummary[]
  suggested_actions: string[]
  confidence: number | null
  root_cause: OpsRootCause | null
  ai_explanation: OpsAiNarrative
}

export interface OpsTimelineEntry {
  at: string
  type: string
  category: string
  description: string
}

export interface OpsIncidentTimeline {
  incident_uuid: string
  alert: OpsAlertSummary
  started_at: string | null
  resolved_at: string | null
  duration_seconds: number | null
  entries: OpsTimelineEntry[]
  entry_count: number
}

export interface OpsRecommendationsResponse {
  recommendations: OpsRecommendation[]
}

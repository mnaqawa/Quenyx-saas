// Sprint 23 — QynReact Incident Workspace types.
import type { AiNarrative, ExecutionSummary } from './automation'

export interface IncidentSummary {
  uuid: string
  title: string
  severity: string
  status: string
  source: string
  alert_uuid?: string | null
  asset_uuid?: string | null
  opened_at?: string | null
  resolved_at?: string | null
}

export interface TimelineEntry {
  at?: string | null
  type: string
  category?: string | null
  description: string
  metadata?: Record<string, unknown> | null
}

export interface CrossModuleEntry {
  module: string
  name: string
  category: string
  capabilities: string[]
  context: Record<string, unknown>
}

export interface IncidentWorkspaceData {
  incident: IncidentSummary
  description?: string | null
  timeline: TimelineEntry[]
  cross_module: { modules: CrossModuleEntry[]; module_count: number; generated_at: string }
  automation: ExecutionSummary[]
  evidence: TimelineEntry[]
  knowledge: { available: boolean; note: string }
  resolution?: string | null
  postmortem?: Record<string, unknown> | null
  generated_at: string
}

export interface IncidentCopilotResult {
  conversation_uuid: string
  message_uuid?: string
  answer: AiNarrative
}

export interface IncidentRecommendResult {
  recommendations: AiNarrative
  evidence: Record<string, unknown>
}

export interface IncidentPostmortemResult {
  postmortem_draft: AiNarrative
  note: string
}

// Sprint 24 — QynSupport Service Desk types.

import type { AiNarrative } from './automation'
import type { SearchHit } from './knowledge'

export interface TicketSummary {
  uuid: string
  reference: string | null
  subject: string
  category: string | null
  priority: string
  impact: string | null
  status: string
  assignee: { uuid: string; name: string } | null
  sla_due_at: string | null
  updated_at: string | null
}

export interface TicketDetail extends TicketSummary {
  description: string | null
  source: string
  incident_uuid: string | null
  asset_uuid: string | null
  requester: { uuid: string; name: string } | null
  ai_suggestions: Record<string, unknown>
  metadata: Record<string, unknown>
  created_at: string | null
  resolved_at: string | null
}

export interface TicketSuggestions {
  category: string
  priority: string
  impact: string
  suggested_sla: { priority: string; hours: number; due_at: string }
  suggested_assignee: { available: boolean; reason?: string; user?: { uuid: string; name: string } | null; resolved_in_category?: number }
  related_incidents: SearchHit[]
  related_runbooks: SearchHit[]
  available_runbooks: number
}

export interface TicketAnalyzeResult {
  ticket_uuid: string
  suggestions: TicketSuggestions
  ai_rationale: AiNarrative
  note: string
}

export interface TicketCopilotResult {
  conversation_uuid: string
  message_uuid: string
  answer: AiNarrative
  evidence: Record<string, unknown>
}

// Sprint 24 — QynNotify Notification Center types.

import type { AiNarrative } from './automation'

export interface NotificationSummary {
  uuid: string
  type: string
  severity: string
  title: string
  source: string
  urgency_score: number
  dedup_count: number
  channel: string | null
  status: string
  correlation_id: string | null
  recipients: Array<{ uuid: string; name: string; role: string }>
  created_at: string | null
}

export interface CorrelationGroup {
  correlation_id: string
  count: number
  max_urgency: number
}

export interface NotificationListResult {
  notifications: NotificationSummary[]
  correlations: CorrelationGroup[]
}

export interface NotificationDigestResult {
  digest: AiNarrative
  evidence: Record<string, unknown>
}

export interface NotificationExecutiveResult {
  executive_summary: AiNarrative
  evidence: Record<string, unknown>
}

export interface NotificationCopilotResult {
  conversation_uuid: string
  message_uuid: string
  answer: AiNarrative
  evidence: Record<string, unknown>
}

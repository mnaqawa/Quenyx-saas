import type { AiNarrative } from './automation'

/** Sprint 25 — QynBalance Enterprise Cost Intelligence types. */

export interface CostLine {
  resource: string
  count: number
  unit_rate: number | null
  pricing_available: boolean
  monthly_cost: number | null
  currency: string
  note: string | null
}

export interface CostRecommendation {
  key: string
  severity: string
  evidence: string
  recommendation: string
}

export interface CostOverview {
  currency: string
  pricing_configured: boolean
  generated_at: string
  infrastructure: {
    lines: CostLine[]
    estimated_monthly_total: number | null
    pricing_available: boolean
    note: string | null
  }
  license_optimization: Record<string, unknown>
  asset_utilization: Record<string, unknown>
  automation_savings: Record<string, unknown>
  capacity_optimization: Record<string, unknown>
  cloud_optimization: Record<string, unknown>
  budget_forecast: Record<string, unknown>
  recommendations: CostRecommendation[]
}

export interface CostCopilotResult {
  conversation_uuid: string
  message_uuid: string
  answer: AiNarrative
  evidence: Record<string, unknown>
}

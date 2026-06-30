// Sprint 22 — QynAsset Asset Intelligence types.
// All resource identifiers are UUIDs (never numeric). Field names mirror the Laravel backend
// (snake_case). The backend strips the {success,data} envelope via apiClient, so these describe the
// inner `data` shape. Asset evidence is always real; capabilities with no data source (licenses,
// lifecycle dates) are reported as not collected.

export interface AssetAiNarrative {
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

export interface AssetSummary {
  uuid: string
  name: string
  address: string
  public_ip: string | null
  source: string
  enabled: boolean
  tags: string[]
  os: string | null
  arch: string | null
  has_agent: boolean
  agent: { status: string; agent_version: string | null; last_seen_at: string | null; enrolled_at: string | null } | null
  service_count: number
  stale: boolean
  inactive: boolean
  discovery_confidence: 'high' | 'medium' | 'low'
  created_at: string | null
  updated_at: string | null
}

export interface AssetInventorySummary {
  total: number
  enabled: number
  with_agent: number
  without_agent: number
  online: number
  inactive: number
  by_os: Record<string, number>
  by_source: Record<string, number>
  discovery_confidence: { high: number; medium: number; low: number }
}

export interface AssetRecommendation {
  type: string
  severity: string
  title: string
  target: string
  target_uuid?: string
  rationale: string
  evidence: Array<Record<string, unknown>>
}

export interface AssetInvestigationLogItem {
  action: string
  context_type: string | null
  at: string | null
}

export interface AssetCapacityRollup {
  health: Record<string, unknown> | null
  runway: Record<string, unknown> | null
}

export interface AssetOverview {
  inventory_summary: AssetInventorySummary
  discovery: {
    new_asset_count: number
    changed_asset_count: number
    inactive_asset_count: number
    unknown_asset_count: number
    duplicate_count: number
    new_assets: AssetSummary[]
    inactive_assets: AssetSummary[]
  }
  capacity: AssetCapacityRollup
  recent_recommendations: AssetRecommendation[]
  recent_ai_investigations: AssetInvestigationLogItem[]
  generated_at: string
}

export interface AssetCopilotResponse {
  conversation_uuid: string
  message_uuid: string
  answer: AssetAiNarrative
  evidence: Record<string, unknown>
}

export interface AssetRecommendationsResponse {
  recommendations: AssetRecommendation[]
}

export interface AssetLicenseReview {
  licenses: {
    available: boolean
    reason?: string
    missing_licenses: Array<Record<string, unknown>>
    unused_licenses: Array<Record<string, unknown>>
    utilization: number | null
  }
  compliance_risk: string | null
  optimization_opportunities: Array<Record<string, unknown>>
  required_integration: string
  ai_review: AssetAiNarrative
}

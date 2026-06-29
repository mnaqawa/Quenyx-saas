// Sprint 20 — Unified AI Workspace API types. All resource identifiers are UUIDs (never numeric).
// Field names mirror the Laravel backend (snake_case).

export interface AiWorkspacePermissions {
  role: string
  can_use_ai: boolean
  can_manage_providers: boolean
  can_manage_templates: boolean
  can_view_costs: boolean
  can_administer: boolean
}

export interface AiWorkspaceSummary {
  ai_enabled: boolean
  workspace_enabled: boolean
  default_provider: string
  conversation_count: number
  message_count: number
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  template_count: number
  configured_provider_count: number
  last_activity_at: string | null
}

export interface AiWorkspaceSummaryResponse {
  summary: AiWorkspaceSummary
  permissions: AiWorkspacePermissions
}

export interface AiConversationMessage {
  uuid: string
  role: string
  content: string | null
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  mocked: boolean
  created_at: string | null
}

export interface AiConversation {
  uuid: string
  provider: string
  model: string | null
  status: string
  message_count: number
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  created_at: string | null
  updated_at: string | null
  messages?: AiConversationMessage[]
}

export interface AiSendMessageResponse {
  conversation_uuid: string
  message_uuid: string
  ai_enabled: boolean
  content: string
  mocked: boolean
  usage: { prompt_tokens: number; completion_tokens: number; total_tokens: number }
  provider: string
  model: string | null
  generated_at: string
}

export interface AiActivityItem {
  uuid: string
  action: string
  actor_id: string | null
  provider: string | null
  endpoint: string | null
  metadata: Record<string, unknown>
  occurred_at: string | null
}

export interface AiNotificationItem {
  uuid: string
  type: string
  metadata: Record<string, unknown>
  created_at: string | null
}

export interface AiUsageByProvider {
  provider: string
  conversation_count: number
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
}

export interface AiUsageDaily {
  date: string
  total_tokens: number
  conversation_count: number
}

export interface AiUsage {
  totals: {
    prompt_tokens: number
    completion_tokens: number
    total_tokens: number
    conversation_count: number
  }
  by_provider: AiUsageByProvider[]
  daily: AiUsageDaily[]
}

export interface AiCostLine {
  provider: string
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  pricing_configured: boolean
  cost: number | null
  currency: string
}

export interface AiCosts {
  currency: string
  pricing_configured: boolean
  total_cost: number | null
  by_provider: AiCostLine[]
}

export interface AiPromptTemplate {
  uuid: string
  name: string
  description: string | null
  category: string | null
  body: string
  variables: string[]
  is_shared: boolean
  created_at: string | null
  updated_at: string | null
}

export interface AiPromptTemplateInput {
  name: string
  body: string
  description?: string | null
  category?: string | null
  variables?: string[]
  is_shared?: boolean
}

export interface AiProvider {
  uuid: string
  provider: string
  is_default: boolean
  implemented: boolean
  enabled: boolean
  model: string | null
  secret_configured: boolean
  configured: boolean
  updated_at: string | null
}

export interface AiProviderSettingsInput {
  enabled?: boolean
  model?: string | null
  api_key?: string
  organization?: string
  clear_secrets?: boolean
}

export interface AiPermissionRule {
  role: string
  source?: 'default' | 'override'
  can_use_ai: boolean
  can_manage_providers: boolean
  can_manage_templates: boolean
  can_view_costs: boolean
  can_administer: boolean
}

export interface AiPermissionMatrix {
  roles: AiPermissionRule[]
  caller?: AiWorkspacePermissions
}

// Capability catalog (dynamic, from the Quenyx AI Platform — Sprint 19).
export interface AiSkillDescriptor {
  key?: string
  name?: string
  description?: string
  enabled?: boolean
  priority?: number
  [k: string]: unknown
}

export interface AiCapabilities {
  platform: string
  modules: Array<{ key: string; supported_skills: string[]; supported_contexts: string[] }>
  module_catalog?: unknown
  skills: AiSkillDescriptor[]
  providers: { default: string; available: string[]; implemented: string[] }
  reasoning: { rules: unknown; decision_types: string[] }
  retrieval: { modes: string[] }
  rag: { enabled: boolean; vector_provider: string | null; provider_resolved: boolean }
  supported_contexts: string[]
  generated_at: string
}

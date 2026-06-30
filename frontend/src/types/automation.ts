// Sprint 23 — QynRun Enterprise Automation Platform types.

export interface AiNarrative {
  available: boolean
  ai_enabled?: boolean
  provider?: string
  model?: string
  mocked?: boolean
  content?: string
  error?: string
  generated_at?: string
}

export interface ExecutionAdapterDescriptor {
  key: string
  name: string
  description: string
  category: string
  capabilities: string[]
  supports_rollback: boolean
  operational: boolean
  parameter_schema: Record<string, unknown>
}

export interface AutomationAction {
  key: string
  label: string
  description: string
  adapter_key: string
  category: string
  destructive: boolean
  supports_rollback: boolean
}

export interface AdaptersResponse {
  adapters: ExecutionAdapterDescriptor[]
  live_execution_enabled: boolean
}

export interface ActionsResponse {
  actions: AutomationAction[]
}

export interface WorkflowSummary {
  uuid: string
  name: string
  description?: string | null
  trigger_type: string
  schedule?: string | null
  enabled: boolean
  requires_approval: boolean
  action_count: number
  created_at?: string
}

export interface WorkflowDetail extends WorkflowSummary {
  definition: Record<string, unknown>
}

export interface RunbookSummary {
  uuid: string
  name: string
  category?: string | null
  description?: string | null
  source: string
  status: string
  step_count: number
  created_at?: string
}

export interface RunbookDetail extends RunbookSummary {
  definition: Record<string, unknown>
}

export interface ExecutionSummary {
  uuid: string
  adapter_key: string
  action_key?: string | null
  status: string
  mode: string
  rolled_back: boolean
  duration_ms?: number | null
  incident_id?: number | null
  workflow_id?: number | null
  runbook_id?: number | null
  created_at?: string
  finished_at?: string
}

export interface ExecutionStep {
  step_index: number
  name: string
  status: string
  output?: string
  started_at?: string
  finished_at?: string
}

export interface ExecutionDetail extends ExecutionSummary {
  parameters?: Record<string, unknown>
  context?: Record<string, unknown>
  result?: Record<string, unknown> | null
  error?: string | null
  steps: ExecutionStep[]
  approval?: { uuid: string; status: string; reason?: string | null; decided_at?: string | null } | null
}

export interface ApprovalSummary {
  uuid: string
  status: string
  created_at?: string
  execution: ExecutionSummary | null
}

export interface LearningActionStat {
  action_key: string
  total: number
  succeeded: number
  failed: number
  rolled_back: number
  dry_run: number
  success_rate: number
  avg_duration_ms?: number | null
}

export interface LearningStats {
  total_records: number
  actions: LearningActionStat[]
  generated_at?: string
}

export interface AutomationOverview {
  counts: { workflows: number; runbooks: number; executions: number; pending_approvals: number }
  executions_by_status: Record<string, number>
  adapters: ExecutionAdapterDescriptor[]
  action_catalog: AutomationAction[]
  learning: LearningStats
  recent_executions: ExecutionSummary[]
  live_execution_enabled: boolean
  generated_at: string
}

export interface CopilotResult {
  conversation_uuid: string
  message_uuid?: string
  answer: AiNarrative
}

export interface RunbookSuggestion {
  problem: string
  suggested_runbook: Record<string, unknown>
  ai_rationale: AiNarrative
  note: string
}

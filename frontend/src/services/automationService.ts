import { apiClient } from './apiClient'
import type {
  ActionsResponse,
  AdaptersResponse,
  ApprovalSummary,
  AutomationOverview,
  CopilotResult,
  ExecutionDetail,
  ExecutionSummary,
  LearningStats,
  RunbookDetail,
  RunbookSuggestion,
  RunbookSummary,
  WorkflowDetail,
  WorkflowSummary,
} from '../types/automation'

/**
 * Sprint 23 — QynRun Automation Platform API client.
 *
 * Workspace-UUID scoped (`?workspace=` for reads, `workspace` body field for writes); all identifiers
 * are UUIDs. The backend is registry-driven and SAFE BY DEFAULT: dispatches plan (dry-run) unless live
 * execution is enabled, and every live action requires approval. AI surfaces reuse the shared Quenyx
 * AI runtime.
 */

const BASE = '/api/qynrun'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

function body<T extends object>(workspaceUuid: string, extra: T): T & { workspace: string } {
  return { workspace: workspaceUuid, ...extra }
}

export const automationService = {
  // Discovery
  getAdapters: (w: string) => apiClient.get<AdaptersResponse>(ws('/automation/adapters', w)),
  getActions: (w: string) => apiClient.get<ActionsResponse>(ws('/automation/actions', w)),

  // Workflows
  listWorkflows: (w: string) => apiClient.get<{ workflows: WorkflowSummary[] }>(ws('/automation/workflows', w)),
  getWorkflow: (w: string, uuid: string) => apiClient.get<WorkflowDetail>(ws(`/automation/workflows/${uuid}`, w)),
  createWorkflow: (w: string, payload: Record<string, unknown>) =>
    apiClient.post<WorkflowDetail>(`${BASE}/automation/workflows`, body(w, payload)),
  runWorkflow: (w: string, uuid: string, mode: 'dry_run' | 'live') =>
    apiClient.post<{ mode: string; executions: ExecutionSummary[] }>(`${BASE}/automation/workflows/${uuid}/run`, body(w, { mode })),

  // Runbooks
  listRunbooks: (w: string) => apiClient.get<{ runbooks: RunbookSummary[] }>(ws('/automation/runbooks', w)),
  getRunbook: (w: string, uuid: string) => apiClient.get<RunbookDetail>(ws(`/automation/runbooks/${uuid}`, w)),
  createRunbook: (w: string, payload: Record<string, unknown>) =>
    apiClient.post<RunbookDetail>(`${BASE}/automation/runbooks`, body(w, payload)),
  runRunbook: (w: string, uuid: string, mode: 'dry_run' | 'live') =>
    apiClient.post<{ mode: string; executions: ExecutionSummary[] }>(`${BASE}/automation/runbooks/${uuid}/run`, body(w, { mode })),

  // Executions
  listExecutions: (w: string) => apiClient.get<{ executions: ExecutionSummary[] }>(ws('/automation/executions', w)),
  getExecution: (w: string, uuid: string) => apiClient.get<ExecutionDetail>(ws(`/automation/executions/${uuid}`, w)),
  dispatch: (w: string, payload: Record<string, unknown>) =>
    apiClient.post<ExecutionDetail>(`${BASE}/automation/executions`, body(w, payload)),
  rollback: (w: string, uuid: string) =>
    apiClient.post<ExecutionDetail>(`${BASE}/automation/executions/${uuid}/rollback`, body(w, {})),
  feedback: (w: string, uuid: string, feedback: string) =>
    apiClient.post<{ recorded: boolean }>(`${BASE}/automation/executions/${uuid}/feedback`, body(w, { feedback })),

  // Approvals
  listApprovals: (w: string) => apiClient.get<{ approvals: ApprovalSummary[] }>(ws('/automation/approvals', w)),
  decideApproval: (w: string, uuid: string, approve: boolean, reason?: string) =>
    apiClient.post<ExecutionDetail>(`${BASE}/automation/approvals/${uuid}/decide`, body(w, { approve, ...(reason ? { reason } : {}) })),

  // Learning
  getLearning: (w: string) => apiClient.get<LearningStats>(ws('/automation/learning', w)),

  // Intelligence (AI surface)
  getOverview: (w: string) => apiClient.get<AutomationOverview>(ws('/intelligence/overview', w)),
  copilot: (w: string, message: string, conversation?: string) =>
    apiClient.post<CopilotResult>(`${BASE}/intelligence/copilot`, body(w, { message, ...(conversation ? { conversation } : {}) })),
  suggestRunbook: (w: string, problem: string) =>
    apiClient.post<RunbookSuggestion>(`${BASE}/intelligence/runbooks/suggest`, body(w, { problem })),
  explainExecution: (w: string, uuid: string) =>
    apiClient.post<{ execution: ExecutionDetail; ai_explanation: import('../types/automation').AiNarrative }>(
      `${BASE}/intelligence/executions/${uuid}/explain`,
      body(w, {}),
    ),
}

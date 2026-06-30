import { apiClient } from './apiClient'
import type { CostOverview, CostCopilotResult } from '../types/qynbalance'

/**
 * Sprint 25 — QynBalance Enterprise Cost Intelligence. Reads are workspace-scoped via query param; the
 * cost copilot reuses the shared Quenyx AI conversation surface. No fabricated financials are ever shown.
 */
const BASE = '/api/qynbalance'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

export const qynbalanceService = {
  overview: (w: string) => apiClient.get<CostOverview>(ws('/cost/overview', w)),
  copilot: (w: string, message: string, conversation?: string) =>
    apiClient.post<CostCopilotResult>(`${BASE}/cost/copilot`, { workspace: w, message, conversation }),
}

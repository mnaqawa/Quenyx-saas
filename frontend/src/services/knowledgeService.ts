import { apiClient } from './apiClient'
import type {
  CopilotResult,
  DraftKind,
  DraftResult,
  GlobalTimeline,
  KnowledgeDocumentDetail,
  KnowledgeDocumentSummary,
  KnowledgeGraph,
  KnowledgeOverview,
  KnowledgeSourceDescriptor,
  SearchResult,
} from '../types/knowledge'
import type { AiNarrative } from '../types/automation'

/**
 * Sprint 24 — QynKnow Enterprise Knowledge Platform API client.
 *
 * Workspace-UUID scoped (`?workspace=` for reads, `workspace` body field for writes); UUID-only. Search
 * and the Assistant are registry-driven and grounded in real indexed data; AI surfaces reuse the shared
 * Quenyx AI conversation surface.
 */

const BASE = '/api/qynknow'

function ws(path: string, workspaceUuid: string): string {
  const sep = path.includes('?') ? '&' : '?'
  return `${BASE}${path}${sep}workspace=${encodeURIComponent(workspaceUuid)}`
}

function body<T extends object>(workspaceUuid: string, extra: T): T & { workspace: string } {
  return { workspace: workspaceUuid, ...extra }
}

export const knowledgeService = {
  // Discovery
  getSources: (w: string) => apiClient.get<{ sources: KnowledgeSourceDescriptor[] }>(ws('/sources', w)),

  // Documents
  listDocuments: (w: string, params?: { category?: string; status?: string }) => {
    const qs = new URLSearchParams()
    if (params?.category) qs.set('category', params.category)
    if (params?.status) qs.set('status', params.status)
    const suffix = qs.toString() ? `?${qs.toString()}` : ''
    return apiClient.get<{ documents: KnowledgeDocumentSummary[] }>(ws(`/documents${suffix}`, w))
  },
  getDocument: (w: string, uuid: string) => apiClient.get<KnowledgeDocumentDetail>(ws(`/documents/${uuid}`, w)),
  createDocument: (w: string, payload: Record<string, unknown>) =>
    apiClient.post<KnowledgeDocumentDetail>(`${BASE}/documents`, body(w, payload)),
  updateDocument: (w: string, uuid: string, payload: Record<string, unknown>) =>
    apiClient.put<KnowledgeDocumentDetail>(`${BASE}/documents/${uuid}`, body(w, payload)),
  deleteDocument: (w: string, uuid: string) => apiClient.delete<{ deleted: boolean }>(ws(`/documents/${uuid}`, w)),

  // Enterprise Search / Timeline / Graph
  search: (w: string, q: string, opts?: { mode?: 'keyword' | 'semantic'; types?: string[]; limit?: number }) => {
    const qs = new URLSearchParams({ q })
    if (opts?.mode) qs.set('mode', opts.mode)
    if (opts?.limit) qs.set('limit', String(opts.limit))
    ;(opts?.types ?? []).forEach((t) => qs.append('types[]', t))
    return apiClient.get<SearchResult>(ws(`/search?${qs.toString()}`, w))
  },
  timeline: (w: string, types?: string[]) =>
    apiClient.get<GlobalTimeline>(ws(`/timeline${types && types.length ? `?types=${types.join(',')}` : ''}`, w)),
  graph: (w: string) => apiClient.get<KnowledgeGraph>(ws('/graph', w)),

  // Knowledge Assistant (AI surface)
  getOverview: (w: string) => apiClient.get<KnowledgeOverview>(ws('/intelligence/overview', w)),
  copilot: (w: string, message: string, conversation?: string) =>
    apiClient.post<CopilotResult>(`${BASE}/intelligence/copilot`, body(w, { message, ...(conversation ? { conversation } : {}) })),
  related: (w: string, q: string) =>
    apiClient.post<{ query: string; results: SearchResult['results']; ai_explanation: AiNarrative }>(
      `${BASE}/intelligence/related`,
      body(w, { q }),
    ),
  draft: (w: string, kind: DraftKind, topic: string) =>
    apiClient.post<DraftResult>(`${BASE}/intelligence/draft`, body(w, { kind, topic })),
  explain: (w: string, uuid: string) =>
    apiClient.post<{ document: Record<string, unknown>; explanation: AiNarrative }>(`${BASE}/intelligence/documents/${uuid}/explain`, body(w, {})),
  summarize: (w: string, uuid: string) =>
    apiClient.post<{ document: Record<string, unknown>; summary: AiNarrative }>(`${BASE}/intelligence/documents/${uuid}/summarize`, body(w, {})),
}

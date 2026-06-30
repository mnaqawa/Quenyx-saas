// Sprint 24 — QynKnow Enterprise Knowledge Platform types.

import type { AiNarrative } from './automation'

export interface KnowledgeSourceDescriptor {
  key: string
  name: string
  category: string
  operational: boolean
  document_count: number
}

export interface KnowledgeDocumentSummary {
  uuid: string
  title: string
  category: string | null
  status: string
  format: string
  source_key: string
  tags: string[]
  updated_at: string | null
}

export interface KnowledgeDocumentDetail extends KnowledgeDocumentSummary {
  body: string | null
  external_ref: string | null
  metadata: Record<string, unknown>
  indexed_at: string | null
}

export interface SearchHit {
  type: string
  module: string
  uuid: string
  title: string
  snippet: string
  score: number
  meta?: Record<string, unknown>
  source_key?: string
  tags?: string[]
  updated_at: string | null
}

export interface SearchResult {
  query: string
  mode: string
  total: number
  results: SearchHit[]
  searched_sources: string[]
  generated_at: string
}

export interface TimelineEvent {
  at: string | null
  type: string
  module: string
  title: string
  description: string
  entity_type: string
  entity_uuid: string
}

export interface GlobalTimeline {
  total: number
  events: TimelineEvent[]
  generated_at: string
}

export interface GraphNode {
  id: string
  type: string
  ref: string
  label: string
  meta: Record<string, unknown>
}

export interface GraphEdge {
  from: string
  to: string
  relation: string
}

export interface KnowledgeGraph {
  nodes: GraphNode[]
  edges: GraphEdge[]
  node_count: number
  edge_count: number
  counts_by_type: Record<string, number>
  generated_at: string
}

export interface KnowledgeOverview {
  sources: KnowledgeSourceDescriptor[]
  document_count: number
  by_status: Record<string, number>
  generated_at: string
}

export interface CopilotResult {
  conversation_uuid: string
  message_uuid: string
  answer: AiNarrative
  evidence: Record<string, unknown>
}

export interface DocumentScaffold {
  title: string
  category: string
  status: string
  format: string
  body: string
}

export interface DraftResult {
  kind: string
  topic: string
  ai_draft: AiNarrative
  document_scaffold: DocumentScaffold
  note: string
}

export type DraftKind = 'kb' | 'incident_summary' | 'executive_summary' | 'technical_summary' | 'runbook'

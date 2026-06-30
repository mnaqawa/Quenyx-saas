// Sprint 24 — Collaboration Platform types.

export type CollabEntityType =
  | 'incident' | 'ticket' | 'document' | 'execution' | 'asset' | 'alert' | 'workflow' | 'runbook' | 'notification'

export type CollabRole = 'watcher' | 'assignee' | 'owner'

export interface CollabComment {
  uuid: string
  body: string
  mentions: string[]
  author: { uuid: string; name: string } | null
  created_at: string | null
}

export interface CollabParticipant {
  role: CollabRole
  user: { uuid: string; name: string } | null
}

export interface CollabThread {
  entity_type: string
  entity_uuid: string
  comments: CollabComment[]
  participants: CollabParticipant[]
  generated_at: string
}

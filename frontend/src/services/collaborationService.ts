import { apiClient } from './apiClient'
import type { CollabEntityType, CollabRole, CollabThread } from '../types/collaboration'

/**
 * Sprint 24 — shared Collaboration Platform API client. Workspace-UUID scoped, UUID-only. Comments,
 * mentions, watchers, and assignments on ANY entity — reusable by every module.
 */

const BASE = '/api/collaboration'

function ws(path: string, workspaceUuid: string, extra: Record<string, string>): string {
  const qs = new URLSearchParams({ workspace: workspaceUuid, ...extra })
  return `${BASE}${path}?${qs.toString()}`
}

function body<T extends object>(workspaceUuid: string, extra: T): T & { workspace: string } {
  return { workspace: workspaceUuid, ...extra }
}

export const collaborationService = {
  thread: (w: string, entityType: CollabEntityType, entityUuid: string) =>
    apiClient.get<CollabThread>(ws('/thread', w, { entity_type: entityType, entity_uuid: entityUuid })),
  comment: (w: string, entityType: CollabEntityType, entityUuid: string, body_: string, mentions: string[] = []) =>
    apiClient.post<{ uuid: string; thread: CollabThread }>(`${BASE}/comments`, body(w, { entity_type: entityType, entity_uuid: entityUuid, body: body_, mentions })),
  addParticipant: (w: string, entityType: CollabEntityType, entityUuid: string, userUuid: string, role: CollabRole) =>
    apiClient.post<CollabThread>(`${BASE}/participants`, body(w, { entity_type: entityType, entity_uuid: entityUuid, user_uuid: userUuid, role })),
}

import { useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

/**
 * QynSight routes live under /app/workspaces/:id/observe/* — the URL workspace id
 * must win over localStorage context so hosts/services load for the workspace being viewed.
 */
export function useObserveWorkspaceId(): string | null {
  const { id } = useParams<{ id: string }>()
  const { selectedWorkspaceId } = useWorkspaceContext()
  return id ?? selectedWorkspaceId
}

import { useCallback, useEffect, useState } from 'react'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

/**
 * Sprint 20 — resolves the selected workspace's UUID for the Unified AI Workspace APIs. The platform
 * scopes AI by workspace UUID (not the numeric id), so pages render a "select a workspace" empty
 * state until a workspace with a UUID is available.
 */
export function useAiWorkspaceUuid(): { workspaceUuid: string | null; hasWorkspace: boolean } {
  const { selectedWorkspace, selectedWorkspaceId } = useWorkspaceContext()
  const workspaceUuid = selectedWorkspace?.uuid ?? null
  return { workspaceUuid, hasWorkspace: Boolean(selectedWorkspaceId) }
}

export interface AiAsyncState<T> {
  data: T | null
  loading: boolean
  error: string | null
  reload: () => void
}

/**
 * Generic workspace-scoped fetch hook following the platform's useState/useEffect/cancelled pattern.
 * The fetcher receives the resolved workspace UUID; nothing runs until one is available.
 */
export function useAiResource<T>(
  fetcher: (workspaceUuid: string) => Promise<T>,
  deps: ReadonlyArray<unknown> = []
): AiAsyncState<T> {
  const { workspaceUuid } = useAiWorkspaceUuid()
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState<boolean>(true)
  const [error, setError] = useState<string | null>(null)
  const [nonce, setNonce] = useState(0)

  const reload = useCallback(() => setNonce((n) => n + 1), [])

  useEffect(() => {
    if (!workspaceUuid) {
      setData(null)
      setLoading(false)
      setError(null)
      return
    }

    let cancelled = false
    setLoading(true)
    setError(null)

    fetcher(workspaceUuid)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setData(null)
          setError(err instanceof Error ? err.message : 'Failed to load AI workspace data')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [workspaceUuid, nonce, ...deps])

  return { data, loading, error, reload }
}

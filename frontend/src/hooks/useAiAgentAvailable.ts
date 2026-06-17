import { useEffect, useState } from 'react'
import { aiAgentService } from '../services/aiAgentService'

/** True when workspace AI personas endpoint reports availability. */
export function useAiAgentAvailable(workspaceId: number | string | null | undefined): boolean {
  const [available, setAvailable] = useState(false)

  useEffect(() => {
    if (!workspaceId) {
      setAvailable(false)
      return
    }
    let cancelled = false
    aiAgentService
      .getPersonas(Number(workspaceId))
      .then((res) => {
        if (!cancelled) setAvailable(res.available === true)
      })
      .catch(() => {
        if (!cancelled) setAvailable(false)
      })
    return () => {
      cancelled = true
    }
  }, [workspaceId])

  return available
}

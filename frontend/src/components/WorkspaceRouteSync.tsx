import { useEffect, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

/**
 * Keep selected workspace in sync with /app/workspaces/:id routes.
 * Redirects away from repaired "Production Env (legacy N)" shells to the real Production Env.
 */
export function WorkspaceRouteSync() {
  const location = useLocation()
  const navigate = useNavigate()
  const { workspaces, selectedWorkspaceId, setSelectedWorkspaceId, isLoadingWorkspaces } =
    useWorkspaceContext()
  const lastRedirectRef = useRef<string | null>(null)

  useEffect(() => {
    if (isLoadingWorkspaces || workspaces.length === 0) return

    const match = location.pathname.match(/^\/app\/workspaces\/(\d+)(\/.*)?$/)
    if (!match) return

    const urlId = match[1]
    const rest = match[2] ?? ''
    const urlWorkspace = workspaces.find((w) => String(w.id) === urlId)
    if (!urlWorkspace) return

    const productionPreferred = [...workspaces]
      .filter((w) => w.name === 'Production Env')
      .sort((a, b) => Number(a.id) - Number(b.id))[0]

    const isLegacyProduction = /^Production Env \(legacy\b/i.test(urlWorkspace.name || '')
    if (isLegacyProduction && productionPreferred && String(productionPreferred.id) !== urlId) {
      const targetPath = `/app/workspaces/${productionPreferred.id}${rest}${location.search}${location.hash}`
      if (lastRedirectRef.current === targetPath) return
      lastRedirectRef.current = targetPath
      setSelectedWorkspaceId(productionPreferred.id)
      navigate(targetPath, { replace: true })
      return
    }

    if (urlId !== String(selectedWorkspaceId)) {
      setSelectedWorkspaceId(urlId)
    }
  }, [
    isLoadingWorkspaces,
    workspaces,
    location.pathname,
    location.search,
    location.hash,
    selectedWorkspaceId,
    setSelectedWorkspaceId,
    navigate,
  ])

  return null
}

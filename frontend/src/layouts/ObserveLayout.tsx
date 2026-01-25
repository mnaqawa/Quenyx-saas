import { Outlet, useLocation, Link } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getObservePageTitle } from '../constants/observeRoutes'

export default function ObserveLayout() {
  const location = useLocation()
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspaceContext()

  if (!selectedWorkspaceId) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
        <p className="text-sm text-white/60">Please select a workspace to view ShieldObserve</p>
      </div>
    )
  }

  // Extract current page from path and get friendly title
  const pathParts = location.pathname.split('/')
  const currentPagePath = pathParts[pathParts.length - 1]
  const currentPageTitle = getObservePageTitle(currentPagePath)

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      {/* Minimal breadcrumb - smaller and less prominent */}
      <div className="flex items-center gap-1.5 text-xs text-white/40 mt-2">
        <Link
          to={`/app/workspaces/${selectedWorkspaceId}`}
          className="hover:text-white/60 transition"
        >
          {selectedWorkspace?.name || 'Workspace'}
        </Link>
        <span>/</span>
        <Link
          to={`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`}
          className="hover:text-white/60 transition"
        >
          ShieldObserve
        </Link>
        <span>/</span>
        <span className="text-white/50">{currentPageTitle}</span>
      </div>
      <Outlet />
    </div>
  )
}

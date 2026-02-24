import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getPageTitleFromPath } from '../constants/platformRegistry'

export default function ObserveLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const { selectedWorkspaceId, modulesWithAccess, allowedByKey } = useWorkspaceContext()

  if (!selectedWorkspaceId) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
        <p className="text-sm text-white/60">Please select a workspace to view QynSight</p>
      </div>
    )
  }

  // Check if QynSight is locked
  const observeModule = modulesWithAccess?.find((m) => m.key === 'qynsight')
  const isLocked = observeModule ? !allowedByKey['qynsight'] : false

  // Get page title from platformRegistry
  const currentPageTitle = getPageTitleFromPath(location.pathname)

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      {/* Locked module banner - consistent with ComingSoon */}
      {isLocked && (
        <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-2 text-sm text-yellow-200">
          <div className="flex items-center gap-2">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <span>QynSight is locked. Some features are disabled.</span>
          </div>
        </div>
      )}

      {/* Minimal breadcrumb - smaller and less prominent */}
      <div className="flex items-center gap-1.5 text-xs text-white/40 mt-2">
        <button
          onClick={() => navigate('/app/workspaces')}
          className="hover:text-white/60 transition"
        >
          Workspaces
        </button>
        <span>/</span>
        <button
          onClick={() => navigate(`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`)}
          className="hover:text-white/60 transition"
        >
          QynSight
        </button>
        {currentPageTitle && currentPageTitle !== 'QynSight' && (
          <>
            <span>/</span>
            <span className="text-white/50">{currentPageTitle}</span>
          </>
        )}
      </div>
      <Outlet />
    </div>
  )
}

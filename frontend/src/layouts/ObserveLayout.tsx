import { Outlet, useLocation, Link } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

const observePagesMap: Record<string, string> = {
  'real-time-monitoring': 'Real-time Monitoring',
  'infrastructure-map': 'Infrastructure Map',
  'performance-analytics': 'Performance Analytics',
  'capacity-planning': 'Capacity Planning',
  'alert-management': 'Alert Management',
  'instance-management': 'Instance Management',
  'services': 'Services',
  'reports': 'Reports',
  'data-sources': 'Data Sources',
}

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

  // Extract current page from path
  const pathParts = location.pathname.split('/')
  const currentPagePath = pathParts[pathParts.length - 1]
  const currentPageLabel = observePagesMap[currentPagePath] || 'ShieldObserve'

  return (
    <div className="space-y-6">
      {/* Minimal breadcrumb */}
      <div className="flex items-center gap-2 text-sm text-white/60">
        <Link
          to={`/app/workspaces/${selectedWorkspaceId}`}
          className="hover:text-white transition"
        >
          {selectedWorkspace?.name || 'Workspace'}
        </Link>
        <span>/</span>
        <Link
          to={`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`}
          className="hover:text-white transition"
        >
          ShieldObserve
        </Link>
        <span>/</span>
        <span className="text-white">{currentPageLabel}</span>
      </div>
      <Outlet />
    </div>
  )
}

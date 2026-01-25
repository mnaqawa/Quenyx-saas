import { Outlet, Link, useLocation } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

const observePages = [
  { path: 'real-time-monitoring', label: 'Real-time Monitoring' },
  { path: 'infrastructure-map', label: 'Infrastructure Map' },
  { path: 'performance-analytics', label: 'Performance Analytics' },
  { path: 'capacity-planning', label: 'Capacity Planning' },
  { path: 'alert-management', label: 'Alert Management' },
  { path: 'instance-management', label: 'Instance Management' },
  { path: 'services', label: 'Services' },
  { path: 'reports', label: 'Reports' },
  { path: 'data-sources', label: 'Data Sources' },
]

export default function ObserveLayout() {
  const location = useLocation()
  const { selectedWorkspaceId } = useWorkspaceContext()

  if (!selectedWorkspaceId) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
        <p className="text-sm text-white/60">Please select a workspace to view ShieldObserve</p>
      </div>
    )
  }

  const basePath = `/app/workspaces/${selectedWorkspaceId}/observe`

  return (
    <div className="space-y-6">
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-4">
        <nav className="flex flex-wrap gap-2">
          {observePages.map((page) => {
            const isActive = location.pathname === `${basePath}/${page.path}`
            return (
              <Link
                key={page.path}
                to={`${basePath}/${page.path}`}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                  isActive
                    ? 'bg-sky-500 text-white'
                    : 'border border-white/10 bg-white/5 text-white/70 hover:bg-white/10 hover:text-white'
                }`}
              >
                {page.label}
              </Link>
            )
          })}
        </nav>
      </div>
      <Outlet />
    </div>
  )
}

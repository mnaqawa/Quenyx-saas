import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getAuthToken } from '../services/apiClient'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

/**
 * Guard that ensures a workspace is selected before accessing app routes.
 * Redirects to /app/workspaces if no workspace is selected.
 * Exceptions: allows access to /app/workspaces (list) and /profile without selection.
 */
function WorkspaceGuard() {
  const token = getAuthToken()
  const location = useLocation()
  const { selectedWorkspaceId } = useWorkspaceContext()

  // If not authenticated, let ProtectedRoute handle it
  if (!token) {
    return <Outlet />
  }

  // Allow access to workspaces list page without selection
  if (location.pathname === '/app/workspaces' || location.pathname === '/app/projects') {
    return <Outlet />
  }

  // Allow access to profile page without selection
  if (location.pathname === '/profile') {
    return <Outlet />
  }

  // For workspace detail pages, we'll auto-select in the component (see WorkspaceDetailsPage)
  // But still require selection for other workspace-scoped pages
  if (location.pathname.startsWith('/app/workspaces/') || location.pathname.startsWith('/app/projects/')) {
    // Allow access, but the page component will auto-select
    return <Outlet />
  }

  // If no workspace selected, redirect to workspaces page
  if (!selectedWorkspaceId) {
    return <Navigate to="/app/workspaces" replace />
  }

  return <Outlet />
}

export default WorkspaceGuard

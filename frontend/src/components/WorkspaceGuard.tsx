import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getAuthToken } from '../services/apiClient'
import { useProjectContext } from '../projects/ProjectContext'

/**
 * Guard that ensures a workspace is selected before accessing app routes.
 * Redirects to /app/projects if no workspace is selected.
 * Exception: allows access to /app/projects itself.
 */
function WorkspaceGuard() {
  const token = getAuthToken()
  const location = useLocation()
  const { selectedProjectId } = useProjectContext()

  // If not authenticated, let ProtectedRoute handle it
  if (!token) {
    return <Outlet />
  }

  // Allow access to workspaces page itself (but not project details)
  if (location.pathname === '/app/projects') {
    return <Outlet />
  }
  
  // Allow access to project details page (user can view details without selecting)
  if (location.pathname.startsWith('/app/projects/')) {
    return <Outlet />
  }

  // If no workspace selected, redirect to workspaces page
  if (!selectedProjectId) {
    return <Navigate to="/app/projects" replace />
  }

  return <Outlet />
}

export default WorkspaceGuard

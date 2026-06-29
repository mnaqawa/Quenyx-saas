import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getAuthToken } from '../services/apiClient'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

/**
 * Pages that intentionally work without a selected workspace. Each of these
 * renders its own "no workspace / create one" empty state, so the guard must
 * not bounce them to /app/workspaces (doing so causes onboarding/tour redirect
 * loops for brand-new users who have no workspace yet).
 */
const WORKSPACE_OPTIONAL_PATHS = [
  '/',
  '/dashboard',
  '/getting-started',
  '/app/workspaces',
  '/app/projects',
  '/profile',
  '/subscriptions',
  '/integrations',
  '/settings/access',
  '/settings/members',
]

/**
 * Guard that ensures a workspace is selected before accessing app routes.
 * Redirects to /app/workspaces if no workspace is selected.
 * Exceptions: see WORKSPACE_OPTIONAL_PATHS (pages that handle the no-workspace state).
 */
function WorkspaceGuard() {
  const token = getAuthToken()
  const location = useLocation()
  const { selectedWorkspaceId } = useWorkspaceContext()

  // If not authenticated, let ProtectedRoute handle it
  if (!token) {
    return <Outlet />
  }

  // Allow access to pages that handle the no-workspace state themselves
  if (WORKSPACE_OPTIONAL_PATHS.includes(location.pathname)) {
    return <Outlet />
  }

  // For workspace detail pages, we'll auto-select in the component (see WorkspaceDetailsPage)
  // But still require selection for other workspace-scoped pages
  if (location.pathname.startsWith('/app/workspaces/') || location.pathname.startsWith('/app/projects/')) {
    // Allow access, but the page component will auto-select
    return <Outlet />
  }

  // Unified AI Workspace (Sprint 20) handles the no-workspace state itself (renders a
  // "select a workspace" empty state), so let it through without bouncing.
  if (location.pathname.startsWith('/ai-workspace')) {
    return <Outlet />
  }

  // If no workspace selected, redirect to workspaces page
  if (!selectedWorkspaceId) {
    return <Navigate to="/app/workspaces" replace />
  }

  return <Outlet />
}

export default WorkspaceGuard

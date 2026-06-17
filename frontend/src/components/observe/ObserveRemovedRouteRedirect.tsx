import { Navigate } from 'react-router-dom'

/** Redirects removed QynSight pages to Overview. */
export default function ObserveRemovedRouteRedirect() {
  return <Navigate to="overview" replace />
}

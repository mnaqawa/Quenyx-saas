import { Navigate } from 'react-router-dom'

/** Redirects removed QynSight pages to Real-time Monitoring. */
export default function ObserveRemovedRouteRedirect() {
  return <Navigate to="real-time-monitoring" replace />
}

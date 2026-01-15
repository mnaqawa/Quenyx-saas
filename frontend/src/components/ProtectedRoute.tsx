import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getAuthToken } from '../services/apiClient'

function ProtectedRoute() {
  const token = getAuthToken()
  const location = useLocation()

  if (!token) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  return <Outlet />
}

export default ProtectedRoute

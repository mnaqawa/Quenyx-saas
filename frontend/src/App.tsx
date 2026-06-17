import { Routes, Route, Navigate } from 'react-router-dom'
import { lazy, Suspense, useEffect } from 'react'
import AppLayout from './layouts/AppLayout'
import Dashboard from './pages/Dashboard'
import Subscriptions from './pages/Subscriptions'
import Integrations from './pages/Integrations'
import GettingStarted from './pages/GettingStarted'
import Profile from './pages/Profile'
import WorkspacesPage from './pages/WorkspacesPage'
import WorkspaceDetailsPage from './pages/WorkspaceDetailsPage'
import WorkspaceAccessSettings from './pages/WorkspaceAccessSettings'
import WorkspaceMembers from './pages/WorkspaceMembers'
import Login from './pages/Login'
import Register from './pages/Register'
import InviteAcceptance from './pages/InviteAcceptance'
import ProtectedRoute from './components/ProtectedRoute'
import WorkspaceGuard from './components/WorkspaceGuard'
import ObserveLayout from './layouts/ObserveLayout'
import RealTimeMonitoring from './pages/observe/RealTimeMonitoring'
import InfrastructureMap from './pages/observe/InfrastructureMap'
import PerformanceAnalytics from './pages/observe/PerformanceAnalytics'
const CapacityPlanning = lazy(() => import('./pages/observe/CapacityPlanning'))
const BillingPage = lazy(() => import('./pages/Billing'))
import AlertManagement from './pages/observe/AlertManagement'
import Services from './pages/observe/Services'
import Targets from './pages/observe/Targets'
import ObserveRemovedRouteRedirect from './components/observe/ObserveRemovedRouteRedirect'
import ComingSoon from './pages/ComingSoon'
import { routesByModule } from './constants/platformRegistry'
import { validateRegistryInDevelopment } from './constants/registrySanity'

function App() {
  // Validate platform registry in development mode
  useEffect(() => {
    validateRegistryInDevelopment()
  }, [])

  // Generate QynSight routes from platformRegistry
  const observeRoutes = routesByModule.qynsight || []
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/invites/accept" element={<InviteAcceptance />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<WorkspaceGuard />}>
          <Route path="/" element={<AppLayout />}>
            <Route path="dashboard" element={<Dashboard />} />
            <Route index element={<Dashboard />} />
            {/* Canonical workspace routes */}
            <Route path="app/workspaces" element={<WorkspacesPage />} />
            <Route path="app/workspaces/:id" element={<WorkspaceDetailsPage />} />
            {/* Legacy project routes - redirect to workspaces */}
            <Route path="app/projects" element={<Navigate to="/app/workspaces" replace />} />
            <Route path="app/projects/:id" element={<WorkspaceDetailsPage />} />
            <Route path="subscriptions" element={<Subscriptions />} />
            <Route
              path="app/workspaces/:id/qyncore/billing"
              element={
                <Suspense fallback={<div className="h-40 animate-pulse rounded-2xl border border-white/10 bg-white/5 m-6" />}>
                  <BillingPage />
                </Suspense>
              }
            />
            <Route path="settings/access" element={<WorkspaceAccessSettings />} />
            <Route path="settings/members" element={<WorkspaceMembers />} />
            <Route path="integrations" element={<Integrations />} />
            <Route path="getting-started" element={<GettingStarted />} />
            {/* Legacy help path -> getting started */}
            <Route path="help" element={<Navigate to="/getting-started" replace />} />
            <Route path="profile" element={<Profile />} />
            {/* QynSight routes - generated from platformRegistry */}
            <Route path="app/workspaces/:id/observe" element={<ObserveLayout />}>
              {/* Route mapping: route.key -> component */}
              {observeRoutes.find((r) => r.key === 'real-time-monitoring') && (
                <Route path="real-time-monitoring" element={<RealTimeMonitoring />} />
              )}
              {observeRoutes.find((r) => r.key === 'infrastructure-map') && (
                <Route path="infrastructure-map" element={<InfrastructureMap />} />
              )}
              {observeRoutes.find((r) => r.key === 'performance-analytics') && (
                <Route path="performance-analytics" element={<PerformanceAnalytics />} />
              )}
              {observeRoutes.find((r) => r.key === 'capacity-planning') && (
                <Route
                  path="capacity-planning"
                  element={
                    <Suspense
                      fallback={
                        <div className="space-y-6 p-6">
                          <div className="h-10 w-64 animate-pulse rounded-lg bg-white/5" />
                          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                            {Array.from({ length: 5 }).map((_, i) => (
                              <div key={i} className="h-28 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
                            ))}
                          </div>
                          <div className="h-72 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
                        </div>
                      }
                    >
                      <CapacityPlanning />
                    </Suspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'alert-management') && (
                <Route path="alert-management" element={<AlertManagement />} />
              )}
              <Route path="data-sources" element={<ObserveRemovedRouteRedirect />} />
              <Route path="reports" element={<ObserveRemovedRouteRedirect />} />
              <Route path="instance-management" element={<ObserveRemovedRouteRedirect />} />
              <Route path="instances" element={<ObserveRemovedRouteRedirect />} />
              {observeRoutes.find((r) => r.key === 'services') && (
                <Route path="services" element={<Services />} />
              )}
              {observeRoutes.find((r) => r.key === 'targets') && (
                <Route path="targets" element={<Targets />} />
              )}
              <Route index element={<RealTimeMonitoring />} />
            </Route>
            {/* Module placeholder routes (Coming Soon) */}
            <Route 
              path="app/workspaces/:id/modules/:moduleKey" 
              element={<ComingSoon />} 
            />
          </Route>
        </Route>
      </Route>
    </Routes>
  )
}

export default App

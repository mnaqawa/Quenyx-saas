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
import ObserveRemovedRouteRedirect from './components/observe/ObserveRemovedRouteRedirect'
import { ObservePageSkeleton } from './components/observe/ObservePageSkeleton'
import ComingSoon from './pages/ComingSoon'
import { routesByModule } from './constants/platformRegistry'
import { validateRegistryInDevelopment } from './constants/registrySanity'

const BillingPage = lazy(() => import('./pages/Billing'))
const Overview = lazy(() => import('./pages/observe/Overview'))
const RealTimeMonitoring = lazy(() => import('./pages/observe/RealTimeMonitoring'))
const InfrastructureMap = lazy(() => import('./pages/observe/InfrastructureMap'))
const PerformanceAnalytics = lazy(() => import('./pages/observe/PerformanceAnalytics'))
const CapacityPlanning = lazy(() => import('./pages/observe/CapacityPlanning'))
const AlertManagement = lazy(() => import('./pages/observe/AlertManagement'))
const Services = lazy(() => import('./pages/observe/Services'))
const Targets = lazy(() => import('./pages/observe/Targets'))

function ObserveSuspense({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<ObservePageSkeleton />}>{children}</Suspense>
}

function App() {
  useEffect(() => {
    validateRegistryInDevelopment()
  }, [])

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
            <Route path="app/workspaces" element={<WorkspacesPage />} />
            <Route path="app/workspaces/:id" element={<WorkspaceDetailsPage />} />
            <Route path="app/projects" element={<Navigate to="/app/workspaces" replace />} />
            <Route path="app/projects/:id" element={<WorkspaceDetailsPage />} />
            <Route path="subscriptions" element={<Subscriptions />} />
            <Route
              path="app/workspaces/:id/qyncore/billing"
              element={
                <Suspense fallback={<ObservePageSkeleton />}>
                  <BillingPage />
                </Suspense>
              }
            />
            <Route path="settings/access" element={<WorkspaceAccessSettings />} />
            <Route path="settings/members" element={<WorkspaceMembers />} />
            <Route path="integrations" element={<Integrations />} />
            <Route path="getting-started" element={<GettingStarted />} />
            <Route path="help" element={<Navigate to="/getting-started" replace />} />
            <Route path="profile" element={<Profile />} />
            <Route path="app/workspaces/:id/observe" element={<ObserveLayout />}>
              {observeRoutes.find((r) => r.key === 'overview') && (
                <Route
                  path="overview"
                  element={
                    <ObserveSuspense>
                      <Overview />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'real-time-monitoring') && (
                <Route
                  path="real-time-monitoring"
                  element={
                    <ObserveSuspense>
                      <RealTimeMonitoring />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'infrastructure-map') && (
                <Route
                  path="infrastructure-map"
                  element={
                    <ObserveSuspense>
                      <InfrastructureMap />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'performance-analytics') && (
                <Route
                  path="performance-analytics"
                  element={
                    <ObserveSuspense>
                      <PerformanceAnalytics />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'capacity-planning') && (
                <Route
                  path="capacity-planning"
                  element={
                    <ObserveSuspense>
                      <CapacityPlanning />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'alert-management') && (
                <Route
                  path="alert-management"
                  element={
                    <ObserveSuspense>
                      <AlertManagement />
                    </ObserveSuspense>
                  }
                />
              )}
              <Route path="data-sources" element={<ObserveRemovedRouteRedirect />} />
              <Route path="reports" element={<ObserveRemovedRouteRedirect />} />
              <Route path="instance-management" element={<ObserveRemovedRouteRedirect />} />
              <Route path="instances" element={<ObserveRemovedRouteRedirect />} />
              {observeRoutes.find((r) => r.key === 'services') && (
                <Route
                  path="services"
                  element={
                    <ObserveSuspense>
                      <Services />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'targets') && (
                <Route
                  path="targets"
                  element={
                    <ObserveSuspense>
                      <Targets />
                    </ObserveSuspense>
                  }
                />
              )}
              <Route index element={<Navigate to="overview" replace />} />
            </Route>
            <Route path="app/workspaces/:id/modules/:moduleKey" element={<ComingSoon />} />
          </Route>
        </Route>
      </Route>
    </Routes>
  )
}

export default App

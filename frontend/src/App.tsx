import { Routes, Route, Navigate } from 'react-router-dom'
import AppLayout from './layouts/AppLayout'
import Dashboard from './pages/Dashboard'
import Subscriptions from './pages/Subscriptions'
import Integrations from './pages/Integrations'
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
import CapacityPlanning from './pages/observe/CapacityPlanning'
import AlertManagement from './pages/observe/AlertManagement'
import InstanceManagement from './pages/observe/InstanceManagement'
import Services from './pages/observe/Services'
import Reports from './pages/observe/Reports'
import DataSources from './pages/observe/DataSources'
import ComingSoon from './pages/ComingSoon'

function App() {
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
            <Route path="settings/access" element={<WorkspaceAccessSettings />} />
            <Route path="settings/members" element={<WorkspaceMembers />} />
            <Route path="integrations" element={<Integrations />} />
            <Route path="profile" element={<Profile />} />
            {/* ShieldObserve routes */}
            <Route path="app/workspaces/:id/observe" element={<ObserveLayout />}>
              <Route path="real-time-monitoring" element={<RealTimeMonitoring />} />
              <Route path="infrastructure-map" element={<InfrastructureMap />} />
              <Route path="performance-analytics" element={<PerformanceAnalytics />} />
              <Route path="capacity-planning" element={<CapacityPlanning />} />
              <Route path="alert-management" element={<AlertManagement />} />
              <Route path="instance-management" element={<InstanceManagement />} />
              <Route path="services" element={<Services />} />
              <Route path="reports" element={<Reports />} />
              <Route path="data-sources" element={<DataSources />} />
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

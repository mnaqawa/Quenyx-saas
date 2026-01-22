import { Routes, Route } from 'react-router-dom'
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
            <Route path="app/projects" element={<WorkspacesPage />} />
            <Route path="app/projects/:id" element={<WorkspaceDetailsPage />} />
            <Route path="subscriptions" element={<Subscriptions />} />
            <Route path="settings/access" element={<WorkspaceAccessSettings />} />
            <Route path="settings/members" element={<WorkspaceMembers />} />
            <Route path="integrations" element={<Integrations />} />
            <Route path="profile" element={<Profile />} />
          </Route>
        </Route>
      </Route>
    </Routes>
  )
}

export default App

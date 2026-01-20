import { Routes, Route } from 'react-router-dom'
import AppLayout from './layouts/AppLayout'
import Dashboard from './pages/Dashboard'
import Subscriptions from './pages/Subscriptions'
import Integrations from './pages/Integrations'
import Profile from './pages/Profile'
import ProjectsPage from './pages/ProjectsPage'
import ProjectDetailsPage from './pages/ProjectDetailsPage'
import ProjectAccessSettings from './pages/ProjectAccessSettings'
import ProjectMembers from './pages/ProjectMembers'
import Login from './pages/Login'
import ProtectedRoute from './components/ProtectedRoute'

function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/" element={<AppLayout />}>
          <Route path="dashboard" element={<Dashboard />} />
          <Route index element={<Dashboard />} />
          <Route path="app/projects" element={<ProjectsPage />} />
          <Route path="app/projects/:id" element={<ProjectDetailsPage />} />
          <Route path="subscriptions" element={<Subscriptions />} />
          <Route path="settings/access" element={<ProjectAccessSettings />} />
          <Route path="settings/members" element={<ProjectMembers />} />
          <Route path="integrations" element={<Integrations />} />
          <Route path="profile" element={<Profile />} />
        </Route>
      </Route>
    </Routes>
  )
}

export default App

import { Routes, Route } from 'react-router-dom'
import AppLayout from './layouts/AppLayout'
import Dashboard from './pages/Dashboard'
import Subscriptions from './pages/Subscriptions'
import Integrations from './pages/Integrations'
import Profile from './pages/Profile'
import Auth from './pages/Auth'

function App() {
  return (
    <Routes>
      <Route path="/" element={<AppLayout />}>
        <Route index element={<Dashboard />} />
        <Route path="subscriptions" element={<Subscriptions />} />
        <Route path="integrations" element={<Integrations />} />
        <Route path="profile" element={<Profile />} />
        <Route path="auth" element={<Auth />} />
      </Route>
    </Routes>
  )
}

export default App

import { Outlet, Link, useLocation } from 'react-router-dom'
import './AppLayout.css'

function AppLayout() {
  const location = useLocation()

  const isActive = (path: string): boolean => {
    return location.pathname === path
  }

  return (
    <div className="app-layout">
      <aside className="sidebar">
        <div className="sidebar-header">
          <h1>PortShield</h1>
        </div>
        <nav className="sidebar-nav">
          <Link
            to="/"
            className={`nav-link ${isActive('/') ? 'active' : ''}`}
          >
            Dashboard
          </Link>
          <Link
            to="/subscriptions"
            className={`nav-link ${isActive('/subscriptions') ? 'active' : ''}`}
          >
            Subscriptions
          </Link>
          <Link
            to="/integrations"
            className={`nav-link ${isActive('/integrations') ? 'active' : ''}`}
          >
            Integrations
          </Link>
          <Link
            to="/profile"
            className={`nav-link ${isActive('/profile') ? 'active' : ''}`}
          >
            Profile
          </Link>
        </nav>
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  )
}

export default AppLayout
